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

        $x = (int)$this->fakeHolder->x;
        $y = (int)$this->fakeHolder->y;
        $z = (int)$this->fakeHolder->z;
        $x2 = $x + 1; // adjacent slot for the second half of the double chest

        // Save original blocks BEFORE placing fake chests (both halves).
        // هاذي القيمة الحقيقية لأي بلوك كان موجود قبل (حجر، تراب، هواء،
        // أي حاجة) — بترجع بالضبط كيفما هي فـ onClose() تحت، بلا تغيير.
        $this->origBlockId    = $who->getLevel()->getBlockIdAt($x, $y, $z);
        $this->origBlockMeta  = $who->getLevel()->getBlockDataAt($x, $y, $z);
        $this->origBlockId2   = $who->getLevel()->getBlockIdAt($x2, $y, $z);
        $this->origBlockMeta2 = $who->getLevel()->getBlockDataAt($x2, $y, $z);

        // v1.3.3: FIXED — السبب الحقيقي لمشكلة "يخدم أول مرة، ماخدمش
        // ثاني مرة فنفس المكان بالضبط، حتى نبدل وضعية": الكلاينت (MCPE
        // legacy) ما كيبعتش طلب فتح الحاوية إلا إذا "حس" بتغيير فعلي
        // فالبلوك. أول مرة، البلوك الأصلي (مثلاً هواء أو حجر) يتبدل
        // لـChest — تغيير واضح، الكلاينت كيفتح عادي. لكن onClose() كان
        // يرجّع البلوك لحالته الأصلية (client-side فقط)، وبعض كلاينتات
        // legacy كتبقى مخزنة فكاش محلي إنو ذاك المكان "كان Chest" —
        // فلما نرجع نبعث Chest بنفس facing من جديد (onOpen() الثانية)،
        // الكلاينت ما يشوفش فرق حقيقي (Chest → Chest) ومايبعتش طلب فتح.
        //
        // الحل: نبعث الهواء (Air) أولاً بشكل صريح قبل Chest، فكل نصف.
        // هاذ التسلسل (Air ثم Chest) كيجبر الكلاينت يسجل "بلوك جديد
        // اتحط اللحظة" فكل مرة، بغض النظر شنو كانت الحالة المخزنة عندو
        // من قبل. هاذ الخطوة ماشي جزء من استرجاع البلوك الأصلي — هي
        // فقط لحظة وسيطة عابرة (transient) قبل ما نحط Chest الحقيقي.
        // البلوك الأصلي الحقيقي (لو كان حجر مثلاً) يبقى محفوظ فـ
        // origBlockId/origBlockId2 ويرجع صحيح 100% فـ onClose() تحت،
        // مهما كان نوعه — هاذ التسلسل لا يمسه ولا يبدله.
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
        // FIXED: blockData كانت 0 — هذي قيمة facing غير صالحة لصندوق
        // Chest (القيم الصحيحة 2=شمال, 3=جنوب, 4=غرب, 5=شرق). facing
        // غير صالح يخلي الكلاينت يرفض ربط البلوكين كصندوق مزدوج (يبقى
        // فيه فجوة بصرية) وقد يرفض حتى فتح الـGUI بالكامل. نستخدم 2
        // (شمال) ثابتة لكل الحالات — كلا البلوكين بنفس الـfacing.
        $facing = 2; // شمال، ثابتة للبلوكين التوأم
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
        // FIXED: أُضيفت pairx/pairy/pairz — هذي الطريقة الرسمية لربط
        // نصفي صندوق مزدوج فـ NBT الخاص بـ Chest (كل نصف يشير لموقع
        // النصف الآخر). بدونها الكلاينت قد يرسم كل صندوق منفصلاً رغم
        // تطابق الـfacing والتجاور.
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

        // v1.3.2: رجعنا لآلية التأخير (delayed open) بعد ما تبين تجريبيًا
        // إنها ضرورية فعليًا لهاذ الكلاينت (MCPE 0.14.3/0.15.x) — بلاها
        // الكلاينت كيرفض قبول فتح الحاوية أصلاً (الصندوق يبان فوق راس
        // اللاعب فجأة ويختفي، والـGUI يبان ويختفي بسرعة)، رغم أن السيرفر
        // كيرسل الحزم بنجاح 100% (تأكدنا من الكونسول: onOpen() تتنفذ
        // بلا أي خطأ). التزامن الفوري (نفس التيك) اللي جربناه فـ v1.3.1
        // ماكانش كافي لهاذ الكلاينت يستوعب البلوكين قبل يقبل الفتح.
        //
        // زدنا التأخير لـ 2 تيكات (كان تيك واحد) عشان نعطي الكلاينت وقت
        // أكبر يعالج البلوكين + الـNBT قبل يوصل طلب فتح الحاوية.
        //
        // المشكلة الأصلية ("/sell يفتح نص ثانية ثم يسكر وحدو") ماكانتش
        // بسبب وجود التأخير بحد ذاته، بل بسبب غياب حماية عند onClose():
        // لو onClose() تنستدعى مرتين (تلقائي + يدوي) خلال فترة الانتظار،
        // كان يسوي تكرار. هاذ الحماية (idempotency guard) موجودة دابا فـ
        // onClose() تحت — فهي كافية بحالها بلا حاجة نلغي التأخير نفسه.
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
        $openPk->windowid = $who->getWindowId($this);
        $openPk->type     = 0; // نفس قيمة CHEST — صريح، بدون الاعتماد على getNetworkType()
        $openPk->slots    = $this->getSize();
        $openPk->x        = $x;
        $openPk->y        = $y;
        $openPk->z        = $z;
        $who->dataPacket($openPk);

        $this->populateOnOpen($who);
        $this->sendContents($who);
    }

    public function onClose(Player $who) {
        // FIXED: حارس عدم-التكرار (idempotency guard). onClose() ممكن
        // تنستدعى من مسارين — تلقائيًا من onInventoryClose() فور إغلاق
        // اللاعب للـGUI من جهازه، أو صراحة من كود يستدعي removeWindow().
        // لو استُدعي مرتين لنفس اللاعب، لازم ثاني استدعاء ما يسوي شيء —
        // غير كذا راح يرسل بلوكات/حزمة إغلاق مكررة ممكن تتعارض زمنيًا مع
        // فتح النافذة التالية. isset($viewers) هو مصدر الحقيقة: أول
        // استدعاء يمسحه، فأي استدعاء بعده يرجع فورًا.
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
        $closePk->windowid = $who->getWindowId($this);
        $who->dataPacket($closePk);

        unset($this->plugin->activeGUI[strtolower($who->getName())]);

        unset($this->viewers[spl_object_hash($who)]);
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
        // FIXED: كان "Stained Glass Pane" (id:160) — هذا البلوك غير
        // مسجل/غير مدعوم في MCPE 0.14.3/0.15.x (أُضيف رسميًا في
        // إصدارات لاحقة)، فكان يظهر كزجاج أبيض/مكسور بصريًا بدل
        // الزجاج الأسود المقصود. استُبدل بـ "Glass Pane" العادي
        // (id:102) المتوفر فعليًا في هذي النسخ.
        $filler = $this->plugin->markAsMenuItem(Item::get(102, 0, 1)); // Glass pane (عادي، متوافق مع 0.14.3/0.15.x)
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