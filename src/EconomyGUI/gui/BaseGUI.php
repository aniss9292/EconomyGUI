<?php

namespace EconomyGUI\gui;

use EconomyGUI\EconomyGUI;

use pocketmine\inventory\CustomInventory;
use pocketmine\inventory\InventoryType;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\network\protocol\ContainerOpenPacket;
use pocketmine\network\protocol\ContainerClosePacket;
use pocketmine\network\protocol\BlockEntityDataPacket;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\NBT;
use pocketmine\tile\Tile;
use pocketmine\Player;
use pocketmine\item\Item;
use pocketmine\scheduler\Task;

abstract class BaseGUI extends CustomInventory {

    protected $plugin;

    protected $fakeHolder;

    protected $ownerPlayer;

    /** @var int Current page for pagination (0-based) */
    protected $currentPage = 0;

    /** @var string Navigation context: "categories", "items", "buy" */
    protected $navContext = "categories";

    /** @var string|null Current category key being viewed */
    protected $currentCategoryKey = null;

    /** @var string|null Current spawner type key (mob), when browsing spawnerz sub-categories */
    protected $currentSpawnerTypeKey = null;

    /** @var array|null Current item entry for buy screen */
    protected $currentItemEntry = null;

    /** @var string|null If set before onOpen runs, ShopGUI will jump straight into this category instead of showing the categories screen (used by /ss shop to open "spawnerz" directly) */
    protected $pendingCategoryKey = null;

    /** @var int Original block ID at fake position (left chest half) */
    protected $origBlockId = 0;

    /** @var int Original block meta at fake position (left chest half) */
    protected $origBlockMeta = 0;

    /** @var int Original block ID at the adjacent position (right chest half) */
    protected $origBlockId2 = 0;

    /** @var int Original block meta at the adjacent position (right chest half) */
    protected $origBlockMeta2 = 0;

    /** @var int|null windowId captured at onOpen() time (tick 0), reused
     * in finishOpen() 2 ticks later — see fix note in onOpen(). */
    protected $capturedWindowId = null;

    abstract protected function getTitleKey();

    abstract protected function populateOnOpen(Player $who);

    public function __construct(EconomyGUI $plugin, Player $p) {
        $this->plugin      = $plugin;
        $this->ownerPlayer = $p;

        $this->fakeHolder = new FakeHolder(
            (int)$p->x,
            (int)$p->y + 2,
            (int)$p->z
        );

        // Double CHEST = 54 slots
        parent::__construct($this->fakeHolder, InventoryType::get(InventoryType::DOUBLE_CHEST));

        $this->fakeHolder->setInventory($this);
    }

    public function onOpen(Player $who) {
        $this->viewers[spl_object_hash($who)] = $who;

        // FIXED: this is the real cause behind "the chest appears and
        // disappears, and the GUI tries to open but doesn't". getWindowId()
        // used to be called inside finishOpen() (after a two-tick delay via
        // OpenContainerTask), not here. But addWindow() in EconomyGUI.php is
        // what registers the windowId with PocketMine, and this happens to
        // occur in the exact same tick that this onOpen() runs in (tick 0).
        // Between tick 0 and tick 2 (when finishOpen() runs), any other
        // event (opening/closing another window for the same player) could
        // change or clear the windowId registered with PocketMine. The
        // result: finishOpen() would fetch a different or invalid windowId
        // when it sent ContainerOpenPacket, so the client would reject the
        // open immediately because the windowId didn't match anything it
        // had registered. Fix: we store the windowId here, immediately
        // after addWindow (tick 0), and use this saved value in
        // finishOpen() instead of requesting it again.
        $this->capturedWindowId = $who->getWindowId($this);

        $x = (int)$this->fakeHolder->x;
        $y = (int)$this->fakeHolder->y;
        $z = (int)$this->fakeHolder->z;
        $x2 = $x + 1; // adjacent slot for the second half of the double chest

        // Save original blocks BEFORE placing fake chests (both halves).
        // This is the real value of whatever block was there before (stone,
        // dirt, air, anything) — it's restored exactly as-is in onClose()
        // below, unchanged.
        $this->origBlockId    = $who->getLevel()->getBlockIdAt($x, $y, $z);
        $this->origBlockMeta  = $who->getLevel()->getBlockDataAt($x, $y, $z);
        $this->origBlockId2   = $who->getLevel()->getBlockIdAt($x2, $y, $z);
        $this->origBlockMeta2 = $who->getLevel()->getBlockDataAt($x2, $y, $z);

        // v1.3.3: FIXED — the real cause of "works the first time, doesn't
        // work the second time in the exact same spot, until we change
        // position": the client (legacy MCPE) doesn't send a container-open
        // request unless it "feels" an actual block change. The first time,
        // the original block (e.g. air or stone) changes to a Chest — a
        // clear change, the client opens normally. But onClose() would
        // restore the block to its original state (client-side only), and
        // some legacy clients keep a local cache that that spot "was a
        // Chest" — so when we send a Chest again with the same facing
        // (second onOpen()), the client sees no real difference
        // (Chest → Chest) and doesn't send an open request.
        //
        // Fix: we explicitly send Air first before Chest, for each half.
        // This sequence (Air then Chest) forces the client to register "a
        // new block was just placed" every time, regardless of what state
        // it had cached before. This step isn't part of restoring the
        // original block — it's just a brief transient moment before we
        // place the real Chest. The actual original block (say, stone) stays
        // saved in origBlockId/origBlockId2 and is restored correctly 100%
        // in onClose() below, whatever its type — this sequence doesn't
        // touch or change it.
        $airPk          = new UpdateBlockPacket();
        $airPk->x       = $x;
        $airPk->z       = $z;
        $airPk->y       = $y;
        $airPk->blockId = 0; // Air
        $airPk->blockData = 0;
        $airPk->flags   = 0;
        $who->dataPacket($airPk);

        $airPk2          = new UpdateBlockPacket();
        $airPk2->x       = $x2;
        $airPk2->z       = $z;
        $airPk2->y       = $y;
        $airPk2->blockId = 0; // Air
        $airPk2->blockData = 0;
        $airPk2->flags   = 0;
        $who->dataPacket($airPk2);

        // Place chest block (client-side only, flags = 0) - left half
        // FIXED: blockData used to be 0 — this is an invalid facing value
        // for a Chest block (valid values are 2=north, 3=south, 4=west,
        // 5=east). An invalid facing makes the client refuse to link the
        // two blocks as a double chest (leaving a visual gap) and might
        // even refuse to open the GUI entirely. We use a fixed 2 (north)
        // for all cases — both blocks share the same facing.
        $facing = 2; // north, fixed for the twin blocks
        $blockPk          = new UpdateBlockPacket();
        $blockPk->x       = $x;
        $blockPk->z       = $z;
        $blockPk->y       = $y;
        $blockPk->blockId = 54; // Chest
        $blockPk->blockData = $facing;
        $blockPk->flags   = 0;
        $who->dataPacket($blockPk);

        // Place chest block (client-side only, flags = 0) - right half
        // Two adjacent Chest blocks with the SAME facing are what makes
        // the client render and open a wide "double chest" model instead
        // of a single chest.
        $blockPk2          = new UpdateBlockPacket();
        $blockPk2->x       = $x2;
        $blockPk2->z       = $z;
        $blockPk2->y       = $y;
        $blockPk2->blockId = 54; // Chest
        $blockPk2->blockData = $facing;
        $blockPk2->flags   = 0;
        $who->dataPacket($blockPk2);

        // Send tile entity data with custom title for BOTH halves so the
        // client links them into a single double-chest container instead
        // of two separate single chests.
        // FIXED: added pairx/pairy/pairz — this is the official way to link
        // the two halves of a double chest in Chest NBT (each half points
        // to the other half's location). Without it the client might render
        // each chest separately despite matching facing and adjacency.
        $title = (string)$this->plugin->cfg()->get($this->getTitleKey(), "Shop");

        $nbtData = new CompoundTag("", array(
            new StringTag("id", Tile::CHEST),
            new IntTag("x", $x),
            new IntTag("y", $y),
            new IntTag("z", $z),
            new StringTag("CustomName", $title),
            new IntTag("pairx", $x2),
            new IntTag("pairy", $y),
            new IntTag("pairz", $z),
        ));

        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $nbt->setData($nbtData);

        $nbtPk           = new BlockEntityDataPacket();
        $nbtPk->x        = $x;
        $nbtPk->y        = $y;
        $nbtPk->z        = $z;
        $nbtPk->namedtag = $nbt->write();
        $who->dataPacket($nbtPk);

        $nbtData2 = new CompoundTag("", array(
            new StringTag("id", Tile::CHEST),
            new IntTag("x", $x2),
            new IntTag("y", $y),
            new IntTag("z", $z),
            new StringTag("CustomName", $title),
            new IntTag("pairx", $x),
            new IntTag("pairy", $y),
            new IntTag("pairz", $z),
        ));

        $nbt2 = new NBT(NBT::LITTLE_ENDIAN);
        $nbt2->setData($nbtData2);

        $nbtPk2           = new BlockEntityDataPacket();
        $nbtPk2->x        = $x2;
        $nbtPk2->y        = $y;
        $nbtPk2->z        = $z;
        $nbtPk2->namedtag = $nbt2->write();
        $who->dataPacket($nbtPk2);

        // v1.3.2: we went back to the delayed-open mechanism after it was
        // shown experimentally that it's genuinely necessary for this
        // client (MCPE 0.14.3/0.15.x) — without it the client refuses to
        // accept the container open at all (the chest appears above the
        // player's head suddenly and disappears, the GUI appears and
        // disappears quickly), even though the server sends all packets
        // successfully 100% (confirmed via console: onOpen() runs without
        // any error). The immediate sync (same tick) we tried in v1.3.1
        // wasn't enough for this client to process the two blocks before
        // accepting the open.
        //
        // We increased the delay to 2 ticks (was one tick) to give the
        // client more time to process the blocks + NBT before the
        // container-open request arrives.
        //
        // The original bug ("/sell opens for half a second then closes
        // itself") wasn't caused by the delay itself, but by the lack of a
        // guard in onClose(): if onClose() got called twice (automatic +
        // manual) during the waiting period, it would cause a duplicate.
        // This guard (idempotency guard) now exists in onClose() below — so
        // it's sufficient on its own without needing to remove the delay
        // itself.
        $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(
            new OpenContainerTask($this, $who, $x, $y, $z),
            2
        );
    }

    /**
     * Second half of onOpen, run a couple ticks after the chest blocks are
     * placed so the client has already registered both halves as a double
     * chest before it receives the request to open the container.
     */
    public function finishOpen(Player $who, $x, $y, $z) {
        if (!isset($this->viewers[spl_object_hash($who)])) {
            return; // player closed/disconnected before the delayed task ran
        }

        $openPk           = new ContainerOpenPacket();
        // FIXED: we use capturedWindowId (registered in onOpen() tick 0)
        // instead of requesting getWindowId() again here (tick 2) — see the
        // explanation in onOpen().
        $windowId = $this->capturedWindowId !== null
            ? $this->capturedWindowId
            : $who->getWindowId($this); // safety fallback
        $openPk->windowid = $windowId;
        $openPk->type     = 0; // same value as CHEST — explicit, without relying on getNetworkType()
        $openPk->slots    = $this->getSize();
        $openPk->x        = $x;
        $openPk->y        = $y;
        $openPk->z        = $z;
        $who->dataPacket($openPk);

        $this->populateOnOpen($who);
        $this->sendContents($who);
    }

    public function onClose(Player $who) {
        // FIXED: idempotency guard. onClose() can be called from two paths
        // — automatically from onInventoryClose() as soon as the player
        // closes the GUI on their device, or explicitly from code that
        // calls removeWindow(). If it's called twice for the same player,
        // the second call must do nothing — otherwise it will send
        // duplicate blocks/close packets that could conflict timing-wise
        // with opening the next window. isset($viewers) is the source of
        // truth: the first call clears it, so any call after that returns
        // immediately.
        if (!isset($this->viewers[spl_object_hash($who)])) {
            return;
        }

        $x = (int)$this->fakeHolder->x;
        $y = (int)$this->fakeHolder->y;
        $z = (int)$this->fakeHolder->z;
        $x2 = $x + 1;

        // Restore original block (client-side only) - left half
        $blockPk            = new UpdateBlockPacket();
        $blockPk->x         = $x;
        $blockPk->z         = $z;
        $blockPk->y         = $y;
        $blockPk->blockId   = $this->origBlockId;
        $blockPk->blockData = $this->origBlockMeta;
        $blockPk->flags     = 0;
        $who->dataPacket($blockPk);

        // Restore original block (client-side only) - right half
        $blockPk2            = new UpdateBlockPacket();
        $blockPk2->x         = $x2;
        $blockPk2->z         = $z;
        $blockPk2->y         = $y;
        $blockPk2->blockId   = $this->origBlockId2;
        $blockPk2->blockData = $this->origBlockMeta2;
        $blockPk2->flags     = 0;
        $who->dataPacket($blockPk2);

        $closePk           = new ContainerClosePacket();
        // FIXED: same reason — we use capturedWindowId instead of
        // requesting getWindowId() again here, because PocketMine might
        // have changed/cleared this player's window registration before
        // onClose() gets to run.
        $closePk->windowid = $this->capturedWindowId !== null
            ? $this->capturedWindowId
            : $who->getWindowId($this);
        $who->dataPacket($closePk);

        unset($this->plugin->activeGUI[strtolower($who->getName())]);

        unset($this->viewers[spl_object_hash($who)]);
        $this->capturedWindowId = null;
    }

    public function getOwnerPlayer() {
        return $this->ownerPlayer;
    }

    /**
     * Get current page number (0-based)
     */
    public function getCurrentPage() {
        return $this->currentPage;
    }

    /**
     * Set current page number
     */
    public function setCurrentPage($page) {
        $this->currentPage = max(0, (int)$page);
    }

    /**
     * Get navigation context
     */
    public function getNavContext() {
        return $this->navContext;
    }

    /**
     * Set navigation context
     */
    public function setNavContext($context) {
        $this->navContext = $context;
    }

    /**
     * Get current category key
     */
    public function getCurrentCategoryKey() {
        return $this->currentCategoryKey;
    }

    /**
     * Set current category key
     */
    public function setCurrentCategoryKey($key) {
        $this->currentCategoryKey = $key;
    }

    /**
     * Get current spawner type key (mob), when browsing spawnerz sub-categories
     */
    public function getCurrentSpawnerTypeKey() {
        return $this->currentSpawnerTypeKey;
    }

    /**
     * Set current spawner type key
     */
    public function setCurrentSpawnerTypeKey($key) {
        $this->currentSpawnerTypeKey = $key;
    }

    /**
     * Get current item entry (for buy screen)
     */
    public function getCurrentItemEntry() {
        return $this->currentItemEntry;
    }

    /**
     * Set current item entry
     */
    public function setCurrentItemEntry($entry) {
        $this->currentItemEntry = $entry;
    }

    /**
     * Set a category key to jump straight into when the GUI opens,
     * bypassing the categories screen. Must be set before addWindow().
     */
    public function setPendingCategoryKey($key) {
        $this->pendingCategoryKey = $key;
    }

    /**
     * Get and consume the pending category key (read once).
     */
    public function takePendingCategoryKey() {
        $key = $this->pendingCategoryKey;
        $this->pendingCategoryKey = null;
        return $key;
    }

    /**
     * How many content slots per page.
     * Bottom row (slots 45-53) is reserved for navigation.
     * Content uses slots 0-44 (45 slots = 5 rows) in a double chest.
     */
    public function getSlotsPerPage() {
        return 45;
    }

    /**
     * Fill ALL 54 slots with AIR to prevent ghost items.
     * Must be called at the start of every fill method.
     */
    public function clearAllSlots() {
        $air = Item::get(Item::AIR, 0, 0);
        for ($i = 0; $i < 54; $i++) {
            $this->setItem($i, $air);
        }
    }

    /**
     * Create a Back button item
     */
    public function makeBackButton() {
        $meta = (int)$this->plugin->cfg()->get("back-button-meta", 14);
        $name = (string)$this->plugin->cfg()->get("back-button-name", "§r§c« Back");
        $btn  = $this->plugin->markAsMenuItem(Item::get(35, $meta, 1));
        // FIXED: was $btn->setCustomName($name) - Item::setCustomName()
        // silently no-ops on wool (an ItemBlock) on this legacy fork, so
        // the back button's name never actually got set. handleShopClick()
        // identifies the back button purely by matching this name, so a
        // blank name meant back-button clicks matched nothing and fell
        // through with no valid branch - closing the GUI instead of
        // navigating back. setItemDisplay() writes the NBT display tag
        // directly, which reliably works on ItemBlock.
        $this->plugin->setItemDisplay($btn, $name);
        return $btn;
    }

    /**
     * Create a Next Page button item
     */
    public function makeNextButton($page) {
        $btn = $this->plugin->markAsMenuItem(Item::get(35, 11, 1)); // Blue wool
        $this->plugin->setItemDisplay($btn, "§r§9Next » §7Page " . ($page + 2));
        return $btn;
    }

    /**
     * Create a Previous Page button item
     */
    public function makePrevButton($page) {
        $btn = $this->plugin->markAsMenuItem(Item::get(35, 4, 1)); // Yellow wool
        $this->plugin->setItemDisplay($btn, "§r§e« Previous §7Page " . ($page));
        return $btn;
    }

    /**
     * Create a page indicator item
     */
    public function makePageIndicator($current, $total) {
        $btn = $this->plugin->markAsMenuItem(Item::get(35, 0, 1)); // White wool
        $this->plugin->setItemDisplay($btn, "§r§7Page §f" . ($current + 1) . "§7/§f" . $total);
        return $btn;
    }

    /**
     * Place navigation buttons in the bottom row (slots 45-53).
     *
     * Layout (9 slots):
     *   [45]Back  [46]fill  [47]fill  [48]Prev  [49]Page#  [50]fill  [51]fill  [52]fill  [53]Next
     */
    public function placeNavigationButtons($totalPages) {
        $currentPage = $this->currentPage;

        // Fill navigation row with glass pane filler
        // FIXED: used to be "Stained Glass Pane" (id:160) — this block
        // isn't registered/supported in MCPE 0.14.3/0.15.x (officially
        // added in later versions), so it appeared as broken/white glass
        // visually instead of the intended black glass. Replaced with
        // regular "Glass Pane" (id:102) which is actually available in
        // these versions.
        $filler = $this->plugin->markAsMenuItem(Item::get(102, 0, 1)); // Glass pane (regular, compatible with 0.14.3/0.15.x)
        $this->plugin->setItemDisplay($filler, "§r");
        for ($i = 45; $i <= 53; $i++) {
            $this->setItem($i, $filler);
        }

        // Back button at slot 45
        $this->setItem(45, $this->makeBackButton());

        // Page indicator at slot 49
        $this->setItem(49, $this->makePageIndicator($currentPage, $totalPages));

        // Previous button at slot 48 (if not on first page)
        if ($currentPage > 0) {
            $this->setItem(48, $this->makePrevButton($currentPage));
        }

        // Next button at slot 53 (if more pages exist)
        if ($currentPage < $totalPages - 1) {
            $this->setItem(53, $this->makeNextButton($currentPage));
        }
    }
}