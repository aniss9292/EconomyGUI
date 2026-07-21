<?php

namespace EconomyGUI;

use EconomyGUI\gui\ShopGUI;
use EconomyGUI\gui\SellChestGUI;
use EconomyGUI\gui\BaseGUI;
use EconomyGUI\manager\ConfigManager;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\inventory\InventoryTransaction;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\network\protocol\LevelEventPacket;

class EconomyGUI extends PluginBase implements Listener {

    public $activeGUI = array();

    private $guiType = array();

    private $configManager;

    private $eco;

    /** @var \smartspawner\Main|null Soft link to SmartSpawner, used to give real spawner items (NBT type/tier) when a shop entry has spawner_type/spawner_tier */
    private $smartSpawner;

    /** @var object|null Soft link to AzCustomEnchant, used to give enchant books when a shop entry has enchant_key */
    private $azCustomEnchant;

    private $cooldowns = array();

    /** @var array Last sell for undo */
    private $lastSell = array();

    public function onEnable() {
        @mkdir($this->getDataFolder());

        foreach (array("config.yml", "shop.yml", "sell.yml") as $file) {
            if (!file_exists($this->getDataFolder() . $file)) {
                $this->saveResource($file);
            }
        }

        $this->configManager = new ConfigManager($this->getDataFolder());

        $this->eco = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if ($this->eco === null) {
            $this->getLogger()->warning("EconomyAPI not found! Please Install it first");
        }

        // SmartSpawner اختياري: إذا كان موجوداً، يصبح بإمكاننا منح
        // عناصر المولّدات الحقيقية (بـ NBT الصحيح) من فئة "spawnerz".
        $this->smartSpawner = $this->getServer()->getPluginManager()->getPlugin("SmartSpawner");

        // AzCustomEnchant اختياري بنفس الطريقة: نتحقق من وجوده هنا مرة
        // وحدة عند التشغيل، ونستعمل النتيجة لاحقاً لإخفاء فئة
        // "custom_enchants" من شاشة الكاتيغوريز إذا كان غير مفعّل.
        $this->azCustomEnchant = $this->getServer()->getPluginManager()->getPlugin("AzCustomEnchant");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getLogger()->info("§aEconomyGUI Plugin is now Activated!");
    }

    public function cfg() {
        return $this->configManager;
    }

    public function getEconomyAPI() {
        return $this->eco;
    }

    /**
     * @return \smartspawner\Main|null
     */
    public function getSmartSpawner() {
        return $this->smartSpawner;
    }

    /**
     * @return object|null
     */
    public function getAzCustomEnchant() {
        return $this->azCustomEnchant;
    }

    /**
     * A shop category should be hidden from the categories screen when it
     * depends on a soft-dependency plugin that isn't currently installed:
     * - "spawnerz" (identified by its spawner_types key) needs SmartSpawner
     *   to actually hand out a real spawner item.
     * - "custom_enchants" (identified by items carrying enchant_key) needs
     *   AzCustomEnchant to hand out the enchant book.
     * Categories without either marker are always shown regardless of
     * plugin state.
     *
     * @param array $cat
     * @return bool
     */
    private function isCategoryHiddenByMissingDependency(array $cat) {
        if (isset($cat["spawner_types"]) && is_array($cat["spawner_types"])) {
            return $this->smartSpawner === null;
        }

        if (isset($cat["items"]) && is_array($cat["items"])) {
            foreach ($cat["items"] as $entry) {
                if (isset($entry["enchant_key"])) {
                    return $this->azCustomEnchant === null;
                }
            }
        }

        return false;
    }

    // ============================================
    // COMMAND HANDLER
    // ============================================

    public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) {
        switch ($cmd->getName()) {

            case "shop":
                if (!($sender instanceof Player)) { $this->console($sender); return true; }
                if (!$sender->hasPermission("economygui.shop")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                $this->openShop($sender);
                return true;

            case "sell":
                if (!($sender instanceof Player)) { $this->console($sender); return true; }
                if (!$sender->hasPermission("economygui.sell")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                if (isset($args[0])) {
                    $sub = strtolower($args[0]);
                    switch ($sub) {
                        case "hand":
                            return $this->sellHand($sender);
                        case "all":
                            return $this->sellAll($sender);
                        case "undo":
                            return $this->sellUndo($sender);
                        default:
                            $sender->sendMessage("§eUsage: §f/sell §7[hand|all|undo]");
                            return true;
                    }
                }
                $this->openSellChest($sender);
                return true;

            case "shopreload":
                if (!$sender->hasPermission("economygui.reload")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                $this->configManager->reload();
                $sender->sendMessage($this->configManager->getMessage("shop-reloaded"));
                return true;

            case "shopbalance":
                if (!($sender instanceof Player)) { $this->console($sender); return true; }
                if ($this->eco === null) { $sender->sendMessage("§cEconomyAPI not found."); return true; }
                $bal = $this->eco->myMoney($sender->getName());
                $sender->sendMessage($this->configManager->getMessage("balance", array(
                    "symbol"  => $this->configManager->getSymbol(),
                    "balance" => number_format($bal),
                )));
                return true;

            case "shopgui-add":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdAdd($sender, $args);

            case "shopgui-remove":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdRemove($sender, $args);

            case "shopgui-addcat":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdAddCat($sender, $args, "shop");

            case "shopgui-removecat":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdRemoveCat($sender, $args, "shop");

            case "shopgui-listcat":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdListCat($sender, "shop");

            case "sellgui-add":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdSellAdd($sender, $args);

            case "sellgui-remove":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdSellRemove($sender, $args);

            case "sellgui-addcat":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdAddCat($sender, $args, "sell");

            case "sellgui-removecat":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdRemoveCat($sender, $args, "sell");

            case "sellgui-listcat":
                if (!$sender->hasPermission("economygui.admin")) {
                    $sender->sendMessage($this->configManager->getMessage("no-permission")); return true;
                }
                return $this->cmdListCat($sender, "sell");
        }
        return false;
    }

    // ============================================
    // SELL HAND
    // ============================================

    private function sellHand(Player $player) {
        if ($this->eco === null) {
            $player->sendMessage("§cEconomyAPI not found!");
            return true;
        }

        $item = $player->getInventory()->getItemInHand();
        if ($item === null || $item->getId() === Item::AIR) {
            $player->sendTip($this->configManager->getMessage("sell-hand-empty"));
            $this->playTickSound($player, "error");
            return true;
        }

        $pricePerUnit = $this->getSellPricePerUnit($item->getId(), $item->getDamage());
        if ($pricePerUnit === null) {
            $player->sendTip($this->configManager->getMessage("sell-not-sellable"));
            $this->playTickSound($player, "error");
            return true;
        }

        $count = $item->getCount();
        $total = (int)round($pricePerUnit * $count);

        $this->lastSell[strtolower($player->getName())] = array(
            "items" => array(clone $item),
            "money" => $total,
            "time"  => time(),
        );

        $player->getInventory()->setItemInHand(Item::get(Item::AIR, 0, 0));
        $this->eco->addMoney($player->getName(), $total);
        $this->playTickSound($player, "sell");

        $sym = $this->configManager->getSymbol();
        $player->sendTip($this->configManager->getMessage("sell-hand-success", array(
            "amount" => $count,
            "item"   => $item->getName(),
            "symbol" => $sym,
            "price"  => number_format($total),
        )));

        $this->configManager->logTransaction("SELL_HAND", strtolower($player->getName()), $count, $item->getName(), $total);
        return true;
    }

    // ============================================
    // SELL ALL
    // ============================================

    private function sellAll(Player $player) {
        if ($this->eco === null) {
            $player->sendMessage("§cEconomyAPI not found!");
            return true;
        }

        $inventory = $player->getInventory();
        $totalEarned = 0;
        $soldItems = array();
        $soldCount = 0;
        $slotsToClear = array();

        foreach ($inventory->getContents() as $slot => $item) {
            if ($item === null || $item->getId() === Item::AIR) continue;

            $pricePerUnit = $this->getSellPricePerUnit($item->getId(), $item->getDamage());
            if ($pricePerUnit === null) continue;

            $count = $item->getCount();
            $payout = (int)round($pricePerUnit * $count);
            $totalEarned += $payout;
            $soldCount += $count;
            $soldItems[] = clone $item;
            $slotsToClear[] = $slot;
        }

        if ($soldCount === 0) {
            $player->sendTip($this->configManager->getMessage("sell-no-items"));
            $this->playTickSound($player, "error");
            return true;
        }

        foreach ($slotsToClear as $slot) {
            $inventory->clear($slot);
        }

        $this->lastSell[strtolower($player->getName())] = array(
            "items" => $soldItems,
            "money" => $totalEarned,
            "time"  => time(),
        );

        $this->eco->addMoney($player->getName(), $totalEarned);
        $this->playTickSound($player, "sell");

        $sym = $this->configManager->getSymbol();
        $player->sendTip($this->configManager->getMessage("sell-all-success", array(
            "amount" => $soldCount,
            "symbol" => $sym,
            "price"  => number_format($totalEarned),
        )));

        $this->configManager->logTransaction("SELL_ALL", strtolower($player->getName()), $soldCount, "various", $totalEarned);
        return true;
    }

    // ============================================
    // SELL UNDO
    // ============================================

    private function sellUndo(Player $player) {
        if ($this->eco === null) {
            $player->sendMessage("§cEconomyAPI not found!");
            return true;
        }

        $name = strtolower($player->getName());
        if (!isset($this->lastSell[$name])) {
            $player->sendTip($this->configManager->getMessage("sell-no-undo"));
            $this->playTickSound($player, "error");
            return true;
        }

        $last = $this->lastSell[$name];

        if (time() - $last["time"] > 300) {
            $player->sendTip($this->configManager->getMessage("sell-undo-expired"));
            unset($this->lastSell[$name]);
            $this->playTickSound($player, "error");
            return true;
        }

        $money = $this->eco->myMoney($player->getName());
        if ($money < $last["money"]) {
            $player->sendTip($this->configManager->getMessage("sell-undo-no-money"));
            $this->playTickSound($player, "error");
            return true;
        }

        $freeSlots = 0;
        foreach ($player->getInventory()->getContents() as $slot => $item) {
            if ($item === null || $item->getId() === Item::AIR) {
                $freeSlots++;
            }
        }
        if ($freeSlots < count($last["items"])) {
            $player->sendTip($this->configManager->getMessage("sell-undo-no-space"));
            $this->playTickSound($player, "error");
            return true;
        }

        $this->eco->reduceMoney($player->getName(), $last["money"]);

        foreach ($last["items"] as $item) {
            $player->getInventory()->addItem(clone $item);
        }

        $sym = $this->configManager->getSymbol();
        $player->sendTip($this->configManager->getMessage("sell-undo-success", array(
            "symbol" => $sym,
            "price"  => number_format($last["money"]),
        )));

        $this->playTickSound($player, "sell");
        $this->configManager->logTransaction("UNDO", $name, count($last["items"]), "various", $last["money"]);

        unset($this->lastSell[$name]);
        return true;
    }

    // ============================================
    // SELL PRICE LOOKUP
    // ============================================

    public function getSellPricePerUnit($id, $meta) {
        $cats = $this->configManager->getSellCategories();
        foreach ($cats as $cat) {
            if (!isset($cat["items"])) continue;
            foreach ($cat["items"] as $entry) {
                if (!isset($entry["id"], $entry["meta"], $entry["price"])) continue;
                if ((int)$entry["id"] === $id && (int)$entry["meta"] === $meta) {
                    $price  = (int)$entry["price"];
                    $amount = isset($entry["amount"]) ? (int)$entry["amount"] : 1;
                    return $price / $amount;
                }
            }
        }
        return null;
    }

    public function setLastSell($name, array $data) {
        $this->lastSell[$name] = $data;
    }

    public function getSellPriceMap() {
        $map  = array();
        $cats = $this->configManager->getSellCategories();

        foreach ($cats as $cat) {
            if (!isset($cat["items"])) continue;
            foreach ($cat["items"] as $entry) {
                if (!isset($entry["id"], $entry["meta"], $entry["price"])) continue;
                $key = (int)$entry["id"] . ":" . (int)$entry["meta"];
                $price  = (int)$entry["price"];
                $amount = isset($entry["amount"]) ? (int)$entry["amount"] : 1;
                $map[$key] = $price / $amount;
            }
        }
        return $map;
    }

    // ============================================
    // SOUND SYSTEM
    // ============================================

    public function playTickSound(Player $player, $type = "sell") {
        if (!(bool)$this->configManager->get("sounds.enabled", true)) return;

        $pk = new LevelEventPacket();
        $pk->x = (float)$player->x;
        $pk->y = (float)$player->y + 1.0;
        $pk->z = (float)$player->z;
        $pk->data = 0;

        switch ($type) {
            case "sell":
            case "buy":
                $pk->evid = LevelEventPacket::EVENT_SOUND_EXP_PICKUP;
                break;
            case "error":
                $pk->evid = LevelEventPacket::EVENT_SOUND_CLICK_FAIL;
                break;
            default:
                $pk->evid = LevelEventPacket::EVENT_SOUND_EXP_PICKUP;
                break;
        }

        $player->dataPacket($pk);
    }

    // ============================================
    // GUI OPENERS
    // ============================================

    public function openShop(Player $player) {
        $name = strtolower($player->getName());

        // FIXED: إذا عند اللاعب نافذة EconomyGUI قديمة لسع مسجّلة (مثلاً
        // فتح /shop أو /sell بسرعة بعد إغلاق نافذة سابقة)، نسكرها صريحاً
        // الآن (removeWindow يستدعي onClose() فوراً ويكمّلها بالكامل: بيع
        // العناصر، إرجاع البلوك الأصلي، إرسال ContainerClosePacket) قبل
        // نبدأ فتح النافذة الجديدة. بدون هذا، PocketMine يسكر القديمة
        // تلقائيًا فاللحظة اللي نستدعي addWindow، وهذا يخلق تضارب تايمنق
        // مع فتح النافذة الجديدة — الكلاينت يستقبل Open(جديد) وClose(قديم
        // تلقائي) قريبين من بعض فيقرر يسكر هو بنفسه.
        //
        // v1.3.1: آلية OpenContainerTask المؤجلة (تيك واحد) اللي كانت
        // مذكورة هنا سابقًا اتحذفت بالكامل من BaseGUI::onOpen() — كانت
        // هي بالضبط سبب مشكلة "/sell يفتح نص ثانية ثم يسكر وحدو": كانت
        // تخلق فجوة زمنية بين addWindow() (تسجيل الـGUI كمفتوحة فورًا)
        // وبين وصول ContainerOpenPacket الحقيقي للكلاينت (بعد تيك كامل)،
        // وأي حدث يجي فهاذ التيك كان يلغي الفتح بصمت. الحماية اللي تحت
        // (إغلاق أي GUI قديمة قبل فتح الجديدة) تبقى مفيدة وصحيحة برأسها.
        if (isset($this->activeGUI[$name])) {
            $oldGui = $this->activeGUI[$name];
            unset($this->activeGUI[$name]);
            unset($this->guiType[$name]);
            $player->removeWindow($oldGui);
        }

        $gui  = new ShopGUI($this, $player);
        $this->activeGUI[$name] = $gui;
        $this->guiType[$name]   = "shop";
        $player->addWindow($gui);
    }

    /**
     * Open the Shop GUI directly inside a specific category, skipping
     * the categories screen. Used by SmartSpawner's /ss shop to jump
     * straight into the "spawnerz" category.
     *
     * @param Player $player
     * @param string $categoryKey
     * @return bool false if the category doesn't exist
     */
    public function openShopCategory(Player $player, $categoryKey) {
        $categoryKey = strtolower($categoryKey);
        $cats = $this->configManager->getShopCategories();
        if (!isset($cats[$categoryKey])) {
            return false;
        }

        $name = strtolower($player->getName());

        // FIXED: نفس تصحيح openShop() — نسكر أي نافذة EconomyGUI قديمة
        // صريحاً قبل الفتح الجديد، لمنع تضارب التايمنق مع الإغلاق التلقائي.
        if (isset($this->activeGUI[$name])) {
            $oldGui = $this->activeGUI[$name];
            unset($this->activeGUI[$name]);
            unset($this->guiType[$name]);
            $player->removeWindow($oldGui);
        }

        $gui  = new ShopGUI($this, $player);
        $gui->setPendingCategoryKey($categoryKey);
        $this->activeGUI[$name] = $gui;
        $this->guiType[$name]   = "shop";
        $player->addWindow($gui);
        return true;
    }

    public function openSellChest(Player $player) {
        $name = strtolower($player->getName());

        // FIXED: نفس تصحيح openShop() — نسكر أي نافذة EconomyGUI قديمة
        // صريحاً (ونكمّل onClose بالكامل: بيع، إرجاع بلوك، إغلاق حزمة)
        // قبل نبدأ تسلسل فتح النافذة الجديدة، بدل نخلي PocketMine يسكرها
        // تلقائياً بطريقة قد تتضارب زمنياً مع فتح النافذة الجديدة.
        //
        // v1.3.1: السبب الفعلي لمشكلة "/sell يفتح نص ثانية ثم يسكر
        // وحدو" ماكانش هنا — كان فـ BaseGUI::onOpen() اللي كان يأخر فتح
        // الحاوية الحقيقية (ContainerOpenPacket) بتيك كامل عبر
        // OpenContainerTask، بينما addWindow() كان يسجل الـGUI كمفتوحة
        // فورًا. تلك الآلية المؤجلة اتحذفت بالكامل؛ الفتح دلوقتي متزامن
        // (نفس التيك) زي ما كان فـ النسخة القديمة المستقرة. الحماية اللي
        // تحت (إغلاق GUI قديمة قبل فتح الجديدة) تبقى صحيحة ومفيدة برأسها.
        if (isset($this->activeGUI[$name])) {
            $oldGui = $this->activeGUI[$name];
            unset($this->activeGUI[$name]);
            unset($this->guiType[$name]);
            $player->removeWindow($oldGui);
        }

        $gui  = new SellChestGUI($this, $player);
        $this->activeGUI[$name] = $gui;
        $this->guiType[$name]   = "sell-chest";
        $player->addWindow($gui);
    }

    // ============================================
    // SHOP CATEGORY FILLING (PAGINATED - 54 slots)
    // ============================================

    /**
     * Fill the shop GUI with category icons, paginated.
     * Content slots: 0-44 (45 per page)
     * Navigation row: slots 45-53
     */
    public function fillShopCategories(BaseGUI $inv) {
        $inv->clearAllSlots();
        $inv->setNavContext("categories");
        $page = $inv->getCurrentPage();
        $slotsPerPage = $inv->getSlotsPerPage(); // 45

        $categories = $this->configManager->getShopCategories();
        $allCats = array();
        foreach ($categories as $key => $cat) {
            if (!isset($cat["icon"], $cat["name"])) continue;
            // إخفاء الفئات المرتبطة بـ plugin غير متوفر (SmartSpawner
            // لفئة spawnerz، AzCustomEnchant لفئة custom_enchants) بدل
            // عرضها وفشل الشراء لاحقاً.
            if ($this->isCategoryHiddenByMissingDependency($cat)) continue;
            $allCats[] = $cat;
        }

        $totalItems = count($allCats);
        $totalPages = max(1, (int)ceil($totalItems / $slotsPerPage));

        // Clamp page
        if ($page >= $totalPages) {
            $page = $totalPages - 1;
            $inv->setCurrentPage($page);
        }

        $start = $page * $slotsPerPage;
        $slot = 0;

        for ($i = $start; $i < $totalItems && $slot < $slotsPerPage; $i++) {
            $cat = $allCats[$i];
            // Tagged too, even though this is just an admin-configured
            // navigation icon (not a real sellable item) - costs nothing
            // and keeps the "every GUI slot is swept" guarantee uniform.
            // setItemDisplay() used instead of setCustomName() since a
            // category icon can be configured as any block id (including
            // the spawner block), and setCustomName() silently fails on
            // ItemBlock - see the spawnerz fix below for the full story.
            $item = $this->markAsMenuItem(Item::get((int)$cat["icon"], 0, 1));
            $this->setItemDisplay($item, $cat["name"]);
            $inv->setItem($slot, $item);
            $slot++;
        }

        // Place navigation buttons
        $inv->placeNavigationButtons($totalPages);

        // FIXED: على كلاينتات MCPE 0.14.3/0.15.x، تغيير الكاتيغوري كان
        // يعتمد فقط على setItem() الفردي لكل سلوت (~45+ حزمة منفصلة في
        // نفس التيك: clearAllSlots يرسل AIR لكل سلوت، ثم التعبية ترسل
        // العنصر الجديد). الكلاينت القديم يفقد/يخلط بعض هذي الحزم
        // فتظهر بقايا من الكاتيغوري السابقة مختلطة مع الجديدة (هذا
        // اللي شفته بصور Redstone/Food/Misc). الحل: resync كامل واحد
        // (ContainerSetContentPacket) بعد كل تعبية، يضمن أن الكلاينت
        // يستلم الحالة النهائية الصحيحة دفعة واحدة بدل الاعتماد على
        // تتابع حزم فردية قابلة للفقد.
        $who = $inv->getOwnerPlayer();
        if ($who instanceof Player && $who->isOnline()) {
            $inv->sendContents($who);
        }
    }

    // ============================================
    // SPAWNER TYPE FILLING (PAGINATED - 54 slots)
    // ============================================

    /**
     * Fill the shop GUI with spawner type (mob) icons for the "spawnerz"
     * category, paginated. Clicking a mob type drills down into its tiers.
     * Content slots: 0-44 (45 per page)
     * Navigation row: slots 45-53
     */
    public function fillSpawnerTypes(BaseGUI $inv, array $spawnerCat) {
        $inv->clearAllSlots();
        $inv->setNavContext("spawner-types");
        $page = $inv->getCurrentPage();
        $slotsPerPage = $inv->getSlotsPerPage(); // 45

        $types = isset($spawnerCat["spawner_types"]) && is_array($spawnerCat["spawner_types"])
            ? $spawnerCat["spawner_types"] : array();

        $allTypes = array();
        foreach ($types as $typeKey => $typeData) {
            if (!isset($typeData["name"], $typeData["icon"])) continue;
            $allTypes[] = array("key" => $typeKey) + $typeData;
        }

        $totalItems = count($allTypes);
        $totalPages = max(1, (int)ceil($totalItems / $slotsPerPage));

        if ($page >= $totalPages) {
            $page = $totalPages - 1;
            $inv->setCurrentPage($page);
        }

        $start = $page * $slotsPerPage;
        $slot = 0;

        for ($i = $start; $i < $totalItems && $slot < $slotsPerPage; $i++) {
            $typeData = $allTypes[$i];
            // FIXED: was $item->setCustomName($typeData["name"]) - silently
            // did nothing because the icon is often the real spawner block
            // (id 52), an ItemBlock, and setCustomName() doesn't reliably
            // apply to ItemBlock on this fork. The item then displayed its
            // vanilla name ("Monster Spawner") instead of the configured
            // name. setItemDisplay() writes the NBT display tag directly,
            // which does work for ItemBlock.
            $item = $this->markAsMenuItem(Item::get((int)$typeData["icon"], 0, 1));
            $this->setItemDisplay($item, $typeData["name"]);
            $inv->setItem($slot, $item);
            $slot++;
        }

        $inv->placeNavigationButtons($totalPages);

        // FIXED: نفس تصحيح fillShopCategories() — resync كامل بعد
        // التعبية يمنع بقايا السلوتات على الكلاينتات القديمة.
        $who = $inv->getOwnerPlayer();
        if ($who instanceof Player && $who->isOnline()) {
            $inv->sendContents($who);
        }
    }

    /**
     * Fill the shop GUI with items from a specific category, paginated.
     * Content slots: 0-44 (45 per page)
     * Navigation row: slots 45-53
     */
    public function fillShopCategoryItems(BaseGUI $inv, array $cat) {
        $inv->clearAllSlots();
        $inv->setNavContext("items");
        $page = $inv->getCurrentPage();
        $slotsPerPage = $inv->getSlotsPerPage(); // 45

        $items = isset($cat["items"]) ? $cat["items"] : array();
        $validItems = array();
        foreach ($items as $entry) {
            if (!isset($entry["name"], $entry["price"], $entry["id"], $entry["meta"])) continue;
            $validItems[] = $entry;
        }

        $totalItems = count($validItems);
        $totalPages = max(1, (int)ceil($totalItems / $slotsPerPage));

        // Clamp page
        if ($page >= $totalPages) {
            $page = $totalPages - 1;
            $inv->setCurrentPage($page);
        }

        $start = $page * $slotsPerPage;
        $slot = 0;

        for ($i = $start; $i < $totalItems && $slot < $slotsPerPage; $i++) {
            $entry = $validItems[$i];
            // REVISED (per owner request): back to showing the REAL item
            // being sold (real id/meta) so tiles look like the actual
            // product again, instead of a generic bookshelf. Safety is now
            // provided by markAsMenuItem() tagging this stack with a
            // hidden NBT marker, which stripMenuItemsFromInventory() (run
            // from the transaction handler and several other events)
            // uses to detect and instantly delete any tagged item that
            // ends up in a player's real inventory. See the
            // MENU-ITEM MARKER SYSTEM comment block above
            // buildShopItemLore() for the full explanation and its
            // known limitation (this is a fast cleanup, not a hard
            // guarantee, unlike the placeholder approach it replaces).
            $shopItem = $this->markAsMenuItem(Item::get((int)$entry["id"], (int)$entry["meta"], 1));
            $this->setItemDisplay($shopItem, $this->buildShopItemLore($entry));
            $inv->setItem($slot, $shopItem);
            $slot++;
        }

        // Place navigation buttons
        $inv->placeNavigationButtons($totalPages);

        // FIXED: نفس تصحيح fillShopCategories() — هذي الدالة هي اللي
        // تعرض عناصر Redstone/Food/Miscellaneous (وكل الكاتيغوريز
        // الأخرى) فالصور المرسلة، resync كامل بعد التعبية يمنع بقايا
        // السلوتات من الكاتيغوري السابقة على الكلاينتات القديمة.
        $who = $inv->getOwnerPlayer();
        if ($who instanceof Player && $who->isOnline()) {
            $inv->sendContents($who);
        }
    }

    // ============================================
    // BUY SCREEN FILLING (54 slots)
    // ============================================

    /**
     * Fill the buy screen for a specific item.
     * Layout (54 slots = 6 rows, double chest):
     *   Row 0 (0-8):     [4] = Reference item
     *   Row 1 (9-17):    [11] = Buy 1x, [13] = Buy 10x, [15] = Buy 64x
     *   Rows 2-4 (18-44): filler
     *   Row 5 (45-53):   [45] = Back button, rest = filler
     */
    private function fillBuyScreen(BaseGUI $inv, array $entry) {
        $inv->clearAllSlots();
        $inv->setNavContext("buy");
        $inv->setCurrentPage(0);
        $sym = $this->configManager->getSymbol();

        // Reference item at slot 4 (top row center)
        //
        // REVISED (per owner request): back to the REAL item/meta so the
        // preview looks like the actual product. Protected by the same
        // NBT marker + sweep mechanism as the item-list tiles - see the
        // MENU-ITEM MARKER SYSTEM comment above buildShopItemLore().
        $refItem = $this->markAsMenuItem(Item::get((int)$entry["id"], (int)$entry["meta"], 1));
        $this->setItemDisplay($refItem, $this->buildShopItemLore($entry));
        $inv->setItem(4, $refItem);

        // Buy buttons in middle row
        //
        // REVISED (per owner request): the buttons now show the REAL item
        // being sold (real id/meta), stacked to match the actual buy
        // amount (e.g. "Buy 64x" shows a stack of 64 of the real item),
        // so the three buttons look identical to the product itself and
        // differ only by the count shown. The anti-dupe protection from
        // the earlier wool-swap fix is NOT lost by this change: it never
        // depended on the button's id/meta/count in the first place - it
        // depends entirely on the economygui_menu_item NBT marker applied
        // by markAsMenuItem() below. isMenuItem() checks only for that
        // NBT tag, regardless of what item it's attached to, and
        // stripMenuItemsFromInventory() (run on every transaction, close,
        // hotbar switch, interact, drop, and quit - see the ANTI-DUPE
        // SWEEP comments above onInventoryTransaction()) deletes any
        // tagged item found in a player's real inventory on sight. So a
        // real-item button with count=64 is exactly as safe as the old
        // wool button with count=1: neither can survive being smuggled
        // into a real inventory slot.
        //
        // Note: the display count can exceed the item's real max stack
        // size (e.g. 64 spawners or tools that normally cap at 1/16) -
        // this is intentional per owner request, purely cosmetic since
        // these slots are never real inventory slots.
        foreach ($this->configManager->getBuySlots() as $slotDef) {
            $amount     = (int)$slotDef["amount"];
            $slot       = (int)$slotDef["slot"];
            $totalPrice = $amount * (int)$entry["price"];

            $btnItem = $this->markAsMenuItem(Item::get((int)$entry["id"], (int)$entry["meta"], $amount));
            $this->setItemDisplay($btnItem, "§aBuy §e{$amount}x\n§7Price: §e{$sym}" . number_format($totalPrice));
            $inv->setItem($slot, $btnItem);
        }

        // Filler for the empty middle rows (18-44) and the navigation
        // row (45-53). FIXED: نفس تصحيح BaseGUI.php — استُبدل "Stained
        // Glass Pane" (id:160, غير مدعوم في 0.14.3/0.15.x) بـ "Glass
        // Pane" العادي (id:102).
        $filler = $this->markAsMenuItem(Item::get(102, 0, 1));
        $this->setItemDisplay($filler, "§r");
        for ($i = 18; $i <= 53; $i++) {
            $inv->setItem($i, $filler);
        }
        $inv->setItem(45, $inv->makeBackButton());

        // FIXED: نفس تصحيح fillShopCategories()/fillShopCategoryItems()
        // — resync كامل بعد التعبية يمنع بقايا السلوتات على الكلاينتات
        // القديمة عند فتح شاشة الشراء.
        $who = $inv->getOwnerPlayer();
        if ($who instanceof Player && $who->isOnline()) {
            $inv->sendContents($who);
        }
    }

    // ============================================
    // INVENTORY EVENTS
    // ============================================

    public function onInventoryTransaction(InventoryTransactionEvent $event) {
        $queue = $event->getTransaction();

        foreach ($queue->getTransactions() as $transaction) {
            $inv = $transaction->getInventory();

            if (!($inv instanceof BaseGUI)) continue;

            $player = $inv->getOwnerPlayer();
            if (!($player instanceof Player)) continue;

            $name = strtolower($player->getName());
            if (!isset($this->activeGUI[$name])) continue;

            $type = isset($this->guiType[$name]) ? $this->guiType[$name] : "shop";

            // Sell chest: allow free item movement
            if ($type === "sell-chest") {
                return;
            }

            // Shop GUI: cancel all transactions (the click is a menu action,
            // not a real item move)
            $event->setCancelled(true);

            $slot = $transaction->getSlot();
            $item = $inv->getItem($slot);

            // FIXED: handleShopClick() runs FIRST — it's the call that
            // mutates the GUI's slots (clears old icons, populates the new
            // screen). Only AFTER that do we resync contents to the client.
            // Previously sendContents() ran before the mutation, so the
            // client treated that as the "final" state and then dropped or
            // ignored the slot updates that came right after — leaving the
            // clicked item visually stuck in its old slot until something
            // forced a full resync (e.g. opening a chest).
            if ($type === "shop") {
                $this->handleShopClick($player, $inv, $item, $slot);
            }

            // ANTI-DUPE SWEEP: run immediately after the click is handled
            // and before any resync goes out. This is the earliest point
            // where a tagged menu item could have landed in the player's
            // real inventory via a legacy client's optimistic drag
            // prediction beating our transaction cancel. See
            // MENU-ITEM MARKER SYSTEM comment above buildShopItemLore().
            $this->stripMenuItemsFromInventory($player);

            // Only resync the GUI itself if it's still actually open — the
            // spawner purchase path closes it deliberately (removeWindow)
            // before giving the item, so resending its contents afterward
            // would be pointless (and could harmlessly no-op against an
            // empty viewer list, but skip it for clarity).
            if (isset($this->activeGUI[$name])) {
                $inv->sendContents($player);
            }
            $player->getInventory()->sendContents($player);
            return;
        }
    }

    public function onInventoryClose(InventoryCloseEvent $event) {
        $inv    = $event->getInventory();
        $player = $event->getPlayer();

        if (!($player instanceof Player)) return;

        $name = strtolower($player->getName());

        // ANTI-DUPE SWEEP: closing ANY inventory (not just our own GUIs)
        // is a moment a player could try to "bank" a tagged item by
        // moving on before a resync catches it - sweep here too.
        $this->stripMenuItemsFromInventory($player);

        if ($inv instanceof SellChestGUI) {
            unset($this->activeGUI[$name]);
            unset($this->guiType[$name]);
            return;
        }

        unset($this->activeGUI[$name]);
        unset($this->guiType[$name]);
    }

    /**
     * ANTI-DUPE SWEEP: switching hotbar slots is one of the fastest
     * things a player can do right after a client-side-predicted grab,
     * and is a very likely place to "look at" a duped item. Catch it
     * here too.
     */
    public function onPlayerItemHeld(PlayerItemHeldEvent $event) {
        $this->stripMenuItemsFromInventory($event->getPlayer());
    }

    /**
     * ANTI-DUPE SWEEP: if a player manages to drop a tagged menu item
     * before our other sweeps catch it, cancel the drop entirely (rather
     * than letting a tagged item exist as a ground entity) and clean
     * their inventory besides.
     */
    public function onPlayerDropItem(PlayerDropItemEvent $event) {
        $item = $event->getItem();
        if ($this->isMenuItem($item)) {
            $event->setCancelled(true);
        }
        $this->stripMenuItemsFromInventory($event->getPlayer());
    }

    /**
     * ANTI-DUPE SWEEP: any right-click/interact (placing a block, using
     * an item, opening another container) is another opportunity for a
     * duped tagged item to be "used" - sweep first so the interaction
     * itself can't go through with a tainted item.
     */
    public function onPlayerInteract(PlayerInteractEvent $event) {
        $item = $event->getItem();
        if (!is_null($item) && $this->isMenuItem($item)) {
            $event->setCancelled(true);
        }
        $this->stripMenuItemsFromInventory($event->getPlayer());
    }

    /**
     * ANTI-DUPE SWEEP: final safety net - if a player disconnects while
     * holding a tagged item (e.g. mid-desync before any other event
     * fired), strip it before their inventory is persisted to disk.
     */
    public function onPlayerQuit(PlayerQuitEvent $event) {
        $this->stripMenuItemsFromInventory($event->getPlayer());
    }

    // ============================================
    // SHOP CLICK HANDLER (FIXED - 54 slot layout)
    // ============================================

    /**
     * FIXED: companion to setItemDisplay(). $item->getCustomName() pairs
     * with setCustomName(), which - same as noted on setItemDisplay() -
     * silently returns nothing for ItemBlock-backed items on this legacy
     * fork (spawner block, wool, glass, bookshelf, etc.). Every click
     * handler in handleShopClick() identifies which button was pressed by
     * matching against the item's display name, so when that name read
     * came back empty for a block-based item, NOTHING matched - the
     * click fell through with no valid branch, which is exactly what was
     * closing the GUI entirely on categories like spawnerz and on any
     * purchase of a block item. This reads the NBT `display.Name` tag
     * directly instead, which is reliably populated by setItemDisplay().
     * @param Item $item
     * @return string
     */
    private function getItemDisplayName(Item $item) {
        $nbt = $item->getNamedTag();
        if (!is_null($nbt) && isset($nbt->display) && isset($nbt->display->Name)) {
            return (string)$nbt->display->Name->getValue();
        }
        // Fall back to the core method too, in case some item types on
        // this fork DO implement it correctly - never worse than before.
        return (string)$item->getCustomName();
    }

    private function handleShopClick(Player $player, BaseGUI $inv, Item $item, $slot) {
        $itemName = $this->getItemDisplayName($item);
        $backName = (string)$this->configManager->get("back-button-name", "§r§c« Back");

        // ---- NAVIGATION ROW (slots 45-53) ----

        // Back button at slot 45
        if ($slot === 45 && $itemName === $backName) {
            $context = $inv->getNavContext();

            if ($context === "buy") {
                // Go back to the items list. If we were buying a spawner,
                // that's the tier list of a specific mob type (spawner-types
                // level); otherwise it's a normal category's item list.
                $catKey = $inv->getCurrentCategoryKey();
                $typeKey = $inv->getCurrentSpawnerTypeKey();
                if ($catKey !== null) {
                    $cats = $this->configManager->getShopCategories();
                    if ($typeKey !== null && isset($cats[$catKey]["spawner_types"][$typeKey])) {
                        $inv->setCurrentPage(0);
                        $this->fillShopCategoryItems($inv, $cats[$catKey]["spawner_types"][$typeKey]);
                        return;
                    }
                    if (isset($cats[$catKey])) {
                        $inv->setCurrentPage(0);
                        $this->fillShopCategoryItems($inv, $cats[$catKey]);
                        return;
                    }
                }
                $inv->setCurrentPage(0);
                $this->fillShopCategories($inv);
                return;
            }

            if ($context === "items") {
                // If we're viewing a spawner mob type's tier list, go back
                // to the spawner type list. Otherwise go back to categories.
                $catKey = $inv->getCurrentCategoryKey();
                $typeKey = $inv->getCurrentSpawnerTypeKey();
                if ($catKey !== null && $typeKey !== null) {
                    $cats = $this->configManager->getShopCategories();
                    if (isset($cats[$catKey])) {
                        $inv->setCurrentSpawnerTypeKey(null);
                        $inv->setCurrentPage(0);
                        $this->fillSpawnerTypes($inv, $cats[$catKey]);
                        return;
                    }
                }
                $inv->setCurrentPage(0);
                $this->fillShopCategories($inv);
                return;
            }

            if ($context === "spawner-types") {
                // Go back to categories
                $inv->setCurrentPage(0);
                $this->fillShopCategories($inv);
                return;
            }

            // Already at categories - do nothing
            return;
        }

        // Next page button at slot 53
        if ($slot === 53 && strpos($itemName, "§r§9Next »") === 0) {
            $inv->setCurrentPage($inv->getCurrentPage() + 1);
            $context = $inv->getNavContext();
            $cats = $this->configManager->getShopCategories();
            $catKey = $inv->getCurrentCategoryKey();
            $typeKey = $inv->getCurrentSpawnerTypeKey();

            if ($context === "categories") {
                $this->fillShopCategories($inv);
            } elseif ($context === "spawner-types") {
                if ($catKey !== null && isset($cats[$catKey])) {
                    $this->fillSpawnerTypes($inv, $cats[$catKey]);
                }
            } elseif ($context === "items") {
                if ($catKey !== null && $typeKey !== null && isset($cats[$catKey]["spawner_types"][$typeKey])) {
                    $this->fillShopCategoryItems($inv, $cats[$catKey]["spawner_types"][$typeKey]);
                } elseif ($catKey !== null && isset($cats[$catKey])) {
                    $this->fillShopCategoryItems($inv, $cats[$catKey]);
                }
            }
            return;
        }

        // Previous page button at slot 48
        if ($slot === 48 && strpos($itemName, "§r§e« Previous") === 0) {
            $inv->setCurrentPage(max(0, $inv->getCurrentPage() - 1));
            $context = $inv->getNavContext();
            $cats = $this->configManager->getShopCategories();
            $catKey = $inv->getCurrentCategoryKey();
            $typeKey = $inv->getCurrentSpawnerTypeKey();

            if ($context === "categories") {
                $this->fillShopCategories($inv);
            } elseif ($context === "spawner-types") {
                if ($catKey !== null && isset($cats[$catKey])) {
                    $this->fillSpawnerTypes($inv, $cats[$catKey]);
                }
            } elseif ($context === "items") {
                if ($catKey !== null && $typeKey !== null && isset($cats[$catKey]["spawner_types"][$typeKey])) {
                    $this->fillShopCategoryItems($inv, $cats[$catKey]["spawner_types"][$typeKey]);
                } elseif ($catKey !== null && isset($cats[$catKey])) {
                    $this->fillShopCategoryItems($inv, $cats[$catKey]);
                }
            }
            return;
        }

        // Ignore clicks on filler items and page indicator (slots 45-53)
        if ($slot >= 45) {
            return;
        }

        // ---- CONTENT AREA (slots 0-44) ----

        // Buy button click
        if (preg_match('/^§aBuy §e(\d+)x\n§7Price: §e[^\d]*([\d,]+)$/', $itemName, $m)) {
            $buyAmount = (int)$m[1];
            $price     = (int)str_replace(",", "", $m[2]);
            $this->processPurchase($player, $inv, $buyAmount, $price);
            // FIXED: re-sync the player's real inventory AFTER the purchase
            // actually writes the new item into a slot. On legacy MCPE 0.14.3
            // clients, the inventory packet sent before addItem() runs is
            // stale by the time the slot changes, so the client's local
            // cache never gets corrected — the item appears to "vanish"
            // until something (like opening a chest) forces a full resync,
            // then desyncs again the moment it's touched.
            $player->getInventory()->sendContents($player);
            return;
        }

        // Category click (in categories context)
        if ($inv->getNavContext() === "categories") {
            foreach ($this->configManager->getShopCategories() as $catKey => $cat) {
                if (!isset($cat["name"]) || $itemName !== $cat["name"]) continue;

                // دفاعياً: نفس فحص الإخفاء المستعمل في fillShopCategories() -
                // حتى لو وصل كليك بطريقة ما لفئة مخفية (plugin غير متوفر)،
                // ما نخليهوش يدخلها.
                if ($this->isCategoryHiddenByMissingDependency($cat)) continue;

                // "spawnerz" category has mob sub-categories instead of a flat
                // item list — drill into the spawner type screen first.
                if (isset($cat["spawner_types"]) && is_array($cat["spawner_types"])) {
                    $inv->setCurrentCategoryKey($catKey);
                    $inv->setCurrentSpawnerTypeKey(null);
                    $inv->setCurrentPage(0);
                    $this->fillSpawnerTypes($inv, $cat);
                    return;
                }

                if (!isset($cat["items"]) || !is_array($cat["items"])) continue;
                $inv->setCurrentCategoryKey($catKey);
                $inv->setCurrentSpawnerTypeKey(null);
                $inv->setCurrentPage(0);
                $this->fillShopCategoryItems($inv, $cat);
                return;
            }
        }

        // Spawner type click (in spawner-types context) → drill into that mob's tiers
        if ($inv->getNavContext() === "spawner-types") {
            $catKey = $inv->getCurrentCategoryKey();
            $cats = $this->configManager->getShopCategories();
            if ($catKey !== null && isset($cats[$catKey]["spawner_types"]) && is_array($cats[$catKey]["spawner_types"])) {
                foreach ($cats[$catKey]["spawner_types"] as $typeKey => $typeData) {
                    if (!isset($typeData["name"]) || $itemName !== $typeData["name"]) continue;
                    if (!isset($typeData["items"]) || !is_array($typeData["items"])) continue;
                    $inv->setCurrentSpawnerTypeKey($typeKey);
                    $inv->setCurrentPage(0);
                    $this->fillShopCategoryItems($inv, $typeData);
                    return;
                }
            }
        }

        // Item click (in items context) → open buy screen
        if ($inv->getNavContext() === "items") {
            $catKey = $inv->getCurrentCategoryKey();
            $typeKey = $inv->getCurrentSpawnerTypeKey();
            $cats = $this->configManager->getShopCategories();

            // Scope the search to exactly the list currently being viewed
            // (a mob type's 4 tiers, or a normal category's flat items),
            // rather than searching every category — avoids name collisions.
            $searchItems = array();
            if ($catKey !== null && $typeKey !== null && isset($cats[$catKey]["spawner_types"][$typeKey]["items"])) {
                $searchItems = $cats[$catKey]["spawner_types"][$typeKey]["items"];
            } elseif ($catKey !== null && isset($cats[$catKey]["items"])) {
                $searchItems = $cats[$catKey]["items"];
            }

            foreach ($searchItems as $entry) {
                if (!isset($entry["name"], $entry["price"], $entry["id"], $entry["meta"])) continue;
                if ($itemName === $this->buildShopItemLore($entry)) {
                    $inv->setCurrentItemEntry($entry);
                    $this->fillBuyScreen($inv, $entry);
                    return;
                }
            }
        }
    }

    // ============================================
    // MENU-ITEM MARKER SYSTEM (anti-dupe safety net)
    // ============================================
    //
    // Per owner's request, menu tiles (category/item-list icons, buy
    // buttons, buy-screen reference item) go back to being built from the
    // REAL item/id/meta so they look like the actual thing being sold,
    // instead of a generic bookshelf placeholder. To compensate, every
    // one of these tiles is tagged with a hidden NBT marker
    // ("economygui_menu_item" = true). Anything carrying that marker is
    // treated as GUI furniture, never a real player-owned item, and gets
    // stripped out the instant it's detected outside a GUI - on the
    // transaction itself, and again on every event that could let a
    // player actually make use of a duped copy (switching held item,
    // dropping, interacting, closing any inventory, quitting).
    //
    // IMPORTANT (told to the owner explicitly): this reduces the dupe
    // window to a single tick in the best case, but does NOT provide the
    // same hard guarantee as the bookshelf-placeholder approach. Because
    // the tile is the real item again, a legacy 0.14.3/0.15.10 client's
    // own client-side drag prediction can still render it in the player's
    // hotbar for a brief moment before any server-side removal packet
    // arrives, and some legacy clients are known to not fully trust/apply
    // a correction packet that arrives fast after their own predicted
    // move. This sweep minimizes - it does not eliminate - that window.
    //
    // NOTE: plain `const` (no visibility keyword) is used here on purpose
    // - `private const` / `public const` require PHP 7.1+, and this
    // server's Termux PHP runtime is older than that. Class constants are
    // implicitly public either way, which is fine for an internal key
    // string like this.
    const MENU_ITEM_NBT_KEY = "economygui_menu_item";

    /**
     * FIXED (spawner category showing "Monster Spawner" instead of the
     * configured name): Item::setCustomName() silently does nothing on
     * ItemBlock-backed items on this legacy PocketMine fork - block-based
     * items (spawner block id 52, wool, glass, etc.) don't reliably
     * implement it, so the item just falls back to its vanilla localized
     * name. This bit us the moment the spawnerz category started using
     * icon: 52 (the actual spawner block) instead of a plain item icon.
     *
     * This helper writes the display name (and optional lore) directly
     * into the item's NBT `display` compound instead, which is the
     * reliable approach already proven in the SmartSpawner plugin's
     * BaseGUI::createItem(). Use this everywhere instead of
     * $item->setCustomName() / $item->setLore() from now on.
     *
     * @param Item $item
     * @param string $name
     * @param array $lore
     * @return Item the same item, for chaining
     */
    public function setItemDisplay(Item $item, $name = "", array $lore = array()) {
        $nbt = $item->getNamedTag();
        if (is_null($nbt)) {
            $nbt = new \pocketmine\nbt\tag\CompoundTag();
        }

        $displayTags = array();
        if ($name !== "") {
            $displayTags[] = new \pocketmine\nbt\tag\StringTag("Name", $name);
        }
        if (!empty($lore)) {
            $loreTags = array();
            foreach ($lore as $line) {
                $loreTags[] = new \pocketmine\nbt\tag\StringTag("", $line);
            }
            $displayTags[] = new \pocketmine\nbt\tag\ListTag("Lore", $loreTags);
        }
        if (!empty($displayTags)) {
            $nbt->display = new \pocketmine\nbt\tag\CompoundTag("display", $displayTags);
        }

        $item->setNamedTag($nbt);
        return $item;
    }

    /**
     * Tag an Item as GUI-only furniture. Call this on every item placed
     * into a shop menu slot (category icons, item-list tiles, buy
     * buttons, buy-screen reference item, nav/back/filler buttons).
     * Public so BaseGUI's own button builders (makeBackButton(),
     * makeNextButton(), etc.) can tag their output too via $this->plugin.
     * @param Item $item
     * @return Item the same item, for chaining
     */
    public function markAsMenuItem(Item $item) {
        $nbt = $item->getNamedTag();
        if (is_null($nbt)) {
            $nbt = new \pocketmine\nbt\tag\CompoundTag();
        }
        $nbt[self::MENU_ITEM_NBT_KEY] = new \pocketmine\nbt\tag\ByteTag(self::MENU_ITEM_NBT_KEY, 1);
        $item->setNamedTag($nbt);
        return $item;
    }

    /**
     * @param Item|null $item
     * @return bool true if this item is a tagged GUI menu tile and should
     *              never be allowed to sit in a real player inventory.
     */
    private function isMenuItem($item) {
        if (is_null($item) || $item->getId() === Item::AIR) {
            return false;
        }
        $nbt = $item->getNamedTag();
        return !is_null($nbt) && isset($nbt[self::MENU_ITEM_NBT_KEY]);
    }

    /**
     * Scan a player's real inventory (not any GUI) for tagged menu items
     * and strip them out immediately. This is the actual anti-dupe
     * enforcement: no matter how a tagged tile ended up visually in the
     * player's inventory (client-side drag prediction beating our
     * transaction cancel), this sweep removes it the moment we see it,
     * on every event that could give the player a chance to use it.
     *
     * @param Player $player
     * @return int number of tainted stacks removed
     */
    private function stripMenuItemsFromInventory(Player $player) {
        $inventory = $player->getInventory();
        $removed = 0;

        foreach ($inventory->getContents() as $slot => $item) {
            if ($this->isMenuItem($item)) {
                $inventory->clear($slot);
                $removed++;
            }
        }

        // Also check the currently held/offhand item explicitly - on some
        // legacy clients the "held" slot is reported separately from
        // getContents() during the exact tick a hotbar swap happens.
        $held = $inventory->getItemInHand();
        if ($this->isMenuItem($held)) {
            $inventory->setItemInHand(Item::get(Item::AIR));
            $removed++;
        }

        if ($removed > 0) {
            $inventory->sendContents($player);
        }

        return $removed;
    }

    private function buildShopItemLore(array $entry) {
        $sym = $this->configManager->getSymbol();

        // عناصر مولّدات SmartSpawner: نعرض النوع/الدرجة بدل وصف عنصر عادي،
        // لأن id:meta وحدهما (52:0) لا يميزان بين المولدات.
        if (isset($entry["spawner_type"], $entry["spawner_tier"])) {
            $lore = $entry["name"] . "\n§7Tier: §f" . $entry["spawner_tier"]
                  . "\n§aPrice: §e" . $sym . number_format((int)$entry["price"]);
            if (isset($entry["description"])) {
                $lore .= "\n§7" . $entry["description"];
            }
            return $lore;
        }

        $lore = $entry["name"] . "\n§aPrice: §e" . $sym . number_format((int)$entry["price"]);
        if (isset($entry["amount"]) && (int)$entry["amount"] > 1) {
            $lore .= " §7(x" . (int)$entry["amount"] . ")";
        }
        if (isset($entry["sell"])) {
            $lore .= "\n§6Sell: §e" . $sym . number_format((int)$entry["sell"]);
        }
        if (isset($entry["description"])) {
            $lore .= "\n§7" . $entry["description"];
        }
        return $lore;
    }

    private function processPurchase(Player $player, BaseGUI $inv, $buyAmount, $price) {
        $name = strtolower($player->getName());
        $sym  = $this->configManager->getSymbol();

        $cd = (int)$this->configManager->get("purchase-cooldown", 0);
        if ($cd > 0 && isset($this->cooldowns[$name])) {
            $left = $cd - (time() - $this->cooldowns[$name]);
            if ($left > 0) {
                $player->sendPopup("§cWait §e{$left}s §cbefore buying again.");
                return;
            }
        }

        // Use stored item entry
        $matched = $inv->getCurrentItemEntry();
        if ($matched === null) {
            // Fallback: try to find from reference slot 4
            $ref = $inv->getItem(4);
            $matched = $this->findShopEntryByLore($ref ? $this->getItemDisplayName($ref) : "");
        }
        if ($matched === null) return;

        $itemAmount = (int)(isset($matched["amount"]) ? $matched["amount"] : 1);
        $totalItems = $buyAmount * $itemAmount;
        $free       = $player->hasPermission("economygui.free");

        $isSpawner = isset($matched["spawner_type"], $matched["spawner_tier"]);
        $isEnchantBook = isset($matched["enchant_key"]);

        if (!$free) {
            $money = $this->eco->myMoney($player->getName());
            if ($money < $price) {
                $needed = $price - $money;
                $player->sendPopup($this->configManager->getMessage("not-enough-money", array(
                    "symbol" => $sym, "needed" => number_format($needed),
                )));
                $this->playTickSound($player, "error");
                return;
            }
        }

        if (!$player->getInventory()->canAddItem(Item::get((int)$matched["id"], (int)$matched["meta"], $totalItems))) {
            $player->sendPopup($this->configManager->getMessage("inventory-full"));
            $this->playTickSound($player, "error");
            return;
        }

        if ($isSpawner) {
            $spawnerPlugin = $this->smartSpawner;
            if ($spawnerPlugin === null) {
                // SmartSpawner غير مفعّل/غير موجود — لا يمكن منح مولّد حقيقي
                $player->sendPopup("§cSmartSpawner plugin is not available.");
                $this->playTickSound($player, "error");
                return;
            }

            // FIXED (bug report: "buying a spawner closes the shop GUI
            // for no reason"): the previous code deliberately called
            // removeWindow($inv) here BEFORE giving the item, believing
            // that mirrors /ss give (which has no GUI open at all). But
            // from the player's side this looked identical to the GUI
            // randomly slamming shut mid-purchase - jarring and
            // indistinguishable from a bug, especially since every OTHER
            // purchase in this same method leaves the GUI open. The
            // giveSpawnerItem() call below already does its own
            // sendContents()/inventory-full handling safely with the
            // window still open; there was no actual technical reason
            // requiring the window to be closed first. We now leave the
            // GUI open just like the non-spawner branch does, and let
            // the normal post-purchase resync (handleShopClick's caller)
            // refresh it.
            $given = $spawnerPlugin->giveSpawnerItem($player, $matched["spawner_type"], $matched["spawner_tier"], $totalItems);

            if (!$given) {
                $player->sendPopup("§cFailed to give spawner item!");
                $this->playTickSound($player, "error");
                return;
            }

            if (!$free) $this->eco->reduceMoney($player->getName(), $price);

            $this->cooldowns[$name] = time();
            $this->configManager->logTransaction("BUY", $name, $totalItems, $matched["name"], $price);
            $this->playTickSound($player, "buy");

            $msgKey = $free ? "free-purchase" : "purchase-success";
            $player->sendPopup($this->configManager->getMessage($msgKey, array(
                "amount" => $totalItems,
                "item"   => $matched["name"],
                "symbol" => $sym,
                "price"  => number_format($price),
            )));
            return;
        } elseif ($isEnchantBook) {
            // Custom Enchants category: hand out an AzCustomEnchant book
            // rather than a plain Item::get(id, meta) stack, since the
            // enchant identity lives in NBT (AzEnchantBook tag), not in
            // id/meta alone. AzCustomEnchant is a soft-depend, exactly
            // like SmartSpawner above, so this stays fully optional.
            $azEnchant = $this->getServer()->getPluginManager()->getPlugin("AzCustomEnchant");
            if ($azEnchant === null || !method_exists($azEnchant, "createEnchantBookItem")) {
                $player->sendPopup("§cAzCustomEnchant plugin is not available.");
                $this->playTickSound($player, "error");
                return;
            }

            $book = $azEnchant->createEnchantBookItem($matched["enchant_key"]);
            if ($book === null) {
                $player->sendPopup("§cUnknown enchant book!");
                $this->playTickSound($player, "error");
                return;
            }
            $book->setCount($totalItems);

            if (!$free) $this->eco->reduceMoney($player->getName(), $price);
            $player->getInventory()->addItem($book);

            $this->cooldowns[$name] = time();
            $this->configManager->logTransaction("BUY", $name, $totalItems, $matched["name"], $price);
            $this->playTickSound($player, "buy");

            $msgKey = $free ? "free-purchase" : "purchase-success";
            $player->sendPopup($this->configManager->getMessage($msgKey, array(
                "amount" => $totalItems,
                "item"   => $matched["name"],
                "symbol" => $sym,
                "price"  => number_format($price),
            )));
            return;
        } else {
            if (!$free) $this->eco->reduceMoney($player->getName(), $price);
            $player->getInventory()->addItem(Item::get((int)$matched["id"], (int)$matched["meta"], $totalItems));
        }

        $this->cooldowns[$name] = time();
        $this->configManager->logTransaction("BUY", $name, $totalItems, $matched["name"], $price);

        $this->playTickSound($player, "buy");

        $msgKey = $free ? "free-purchase" : "purchase-success";
        $player->sendPopup($this->configManager->getMessage($msgKey, array(
            "amount" => $totalItems,
            "item"   => $matched["name"],
            "symbol" => $sym,
            "price"  => number_format($price),
        )));
    }

    private function findShopEntryByLore($lore) {
        foreach ($this->configManager->getShopCategories() as $cat) {
            if (!isset($cat["items"])) continue;
            foreach ($cat["items"] as $entry) {
                if (!isset($entry["name"], $entry["price"], $entry["id"], $entry["meta"])) continue;
                if ($lore === $this->buildShopItemLore($entry)) return $entry;
            }
        }
        return null;
    }

    private function priceToWoolColor($price) {
        if ($price < 100)  return 5;
        if ($price < 500)  return 4;
        if ($price < 2000) return 1;
        return 14;
    }

    // ============================================
    // ADMIN COMMANDS
    // ============================================

    private function cmdAdd(CommandSender $sender, array $args) {
        if (count($args) < 3) {
            $sender->sendMessage($this->configManager->getMessage("usage-add"));
            return true;
        }
        if (!preg_match('/^(\d+):(\d+)$/', $args[0], $m)) {
            $sender->sendMessage($this->configManager->getMessage("invalid-id"));
            return true;
        }
        $id        = (int)$m[1];
        $meta      = (int)$m[2];
        $price     = (int)$args[1];
        $amount    = (int)$args[2];
        $category  = isset($args[3]) ? strtolower($args[3]) : (string)$this->configManager->get("default-category", "general");
        $sellPrice = isset($args[4]) ? (int)$args[4] : null;

        if ($price <= 0 || $amount <= 0) {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §cPrice and amount must be positive numbers.");
            return true;
        }

        $itemObj = Item::get($id, $meta, 1);
        $name    = "§r§e" . $itemObj->getName();
        $this->configManager->addShopItem($id, $meta, $name, $price, $amount, $category, $sellPrice);

        $sender->sendMessage($this->configManager->getMessage("item-added", array(
            "item"     => $itemObj->getName(),
            "id"       => $id,
            "meta"     => $meta,
            "category" => $category,
            "symbol"   => $this->configManager->getSymbol(),
            "price"    => number_format($price),
            "amount"   => $amount,
        )));
        return true;
    }

    private function cmdRemove(CommandSender $sender, array $args) {
        if (count($args) < 1) {
            $sender->sendMessage($this->configManager->getMessage("usage-remove"));
            return true;
        }
        if (!preg_match('/^(\d+):(\d+)$/', $args[0], $m)) {
            $sender->sendMessage($this->configManager->getMessage("invalid-id"));
            return true;
        }
        $id       = (int)$m[1];
        $meta     = (int)$m[2];
        $category = isset($args[1]) ? strtolower($args[1]) : null;

        $count = $this->configManager->removeShopItem($id, $meta, $category);
        if ($count > 0) {
            $sender->sendMessage($this->configManager->getMessage("item-removed", array("id" => $id, "meta" => $meta)));
        } else {
            $sender->sendMessage($this->configManager->getMessage("item-not-found", array("id" => $id, "meta" => $meta)));
        }
        return true;
    }

    private function cmdSellRemove(CommandSender $sender, array $args) {
        if (count($args) < 1) {
            $sender->sendMessage($this->configManager->getMessage("usage-sell-remove"));
            return true;
        }
        if (!preg_match('/^(\d+):(\d+)$/', $args[0], $m)) {
            $sender->sendMessage($this->configManager->getMessage("invalid-id"));
            return true;
        }
        $id       = (int)$m[1];
        $meta     = (int)$m[2];
        $category = isset($args[1]) ? strtolower($args[1]) : null;

        $count = $this->configManager->removeSellItem($id, $meta, $category);
        if ($count > 0) {
            $sender->sendMessage($this->configManager->getMessage("sell-item-removed", array("id" => $id, "meta" => $meta)));
        } else {
            $sender->sendMessage($this->configManager->getMessage("sell-item-not-found", array("id" => $id, "meta" => $meta)));
        }
        return true;
    }

    private function cmdAddCat(CommandSender $sender, array $args, $type) {
        $prefix = $type === "shop" ? "shop" : "sell";
        if (count($args) < 3) {
            $sender->sendMessage("§eUsage: /{$prefix}gui-addcat <key> <iconId> <displayName>");
            return true;
        }
        $key     = strtolower($args[0]);
        $iconId  = (int)$args[1];
        $display = implode(" ", array_slice($args, 2));

        if ($type === "shop") {
            $exists = $this->configManager->shopCategoryExists($key);
            $ok     = $exists ? false : $this->configManager->addShopCategory($key, $iconId, $display);
        } else {
            $exists = $this->configManager->sellCategoryExists($key);
            $ok     = $exists ? false : $this->configManager->addSellCategory($key, $iconId, $display);
        }

        if ($exists) {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §cCategory §e{$key} §calready exists in the {$prefix}!");
        } else {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §aCategory §e{$key} §acreated in §e{$prefix} §awith icon §e{$iconId} §aand name §r{$display}§a.");
        }
        return true;
    }

    private function cmdRemoveCat(CommandSender $sender, array $args, $type) {
        $prefix = $type === "shop" ? "shop" : "sell";
        if (count($args) < 1) {
            $sender->sendMessage("§eUsage: /{$prefix}gui-removecat <key>");
            return true;
        }
        $key = strtolower($args[0]);

        if ($type === "shop") {
            $ok = $this->configManager->removeShopCategory($key);
        } else {
            $ok = $this->configManager->removeSellCategory($key);
        }

        if ($ok) {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §aCategory §e{$key} §aand all its items removed from §e{$prefix}§a.");
        } else {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §cCategory §e{$key} §cnot found in {$prefix}.");
        }
        return true;
    }

    private function cmdListCat(CommandSender $sender, $type) {
        if ($type === "shop") {
            $cats = $this->configManager->getShopCategories();
        } else {
            $cats = $this->configManager->getSellCategories();
        }

        $prefix = $type === "shop" ? "Shop" : "Sell";
        if (empty($cats)) {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §eNo categories in {$prefix} yet.");
            return true;
        }

        $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §a- {$prefix} Categories -");
        foreach ($cats as $key => $cat) {
            $name      = isset($cat["name"]) ? $cat["name"] : $key;
            $icon      = isset($cat["icon"]) ? (int)$cat["icon"] : 0;
            $itemCount = isset($cat["items"]) ? count($cat["items"]) : 0;
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §7  §e{$key} §7| §rName: {$name} §7| §7Icon: §e{$icon} §7| §7Items: §e{$itemCount}");
        }
        return true;
    }

    private function cmdSellAdd(CommandSender $sender, array $args) {
        if (count($args) < 3) {
            $sender->sendMessage($this->configManager->getMessage("usage-sell-add"));
            return true;
        }
        if (!preg_match('/^(\d+):(\d+)$/', $args[0], $m)) {
            $sender->sendMessage($this->configManager->getMessage("invalid-id"));
            return true;
        }
        $id       = (int)$m[1];
        $meta     = (int)$m[2];
        $price    = (int)$args[1];
        $amount   = (int)$args[2];
        $category = isset($args[3]) ? strtolower($args[3]) : (string)$this->configManager->get("default-category", "general");

        if ($price <= 0 || $amount <= 0) {
            $sender->sendMessage("§l§8[§cEconomy§eGUI§8]§r §cPrice and amount must be positive numbers.");
            return true;
        }

        $itemObj = Item::get($id, $meta, 1);
        $name    = "§r§e" . $itemObj->getName();
        $this->configManager->addSellItem($id, $meta, $name, $price, $amount, $category);

        $sender->sendMessage($this->configManager->getMessage("sell-item-added", array(
            "item"     => $itemObj->getName(),
            "id"       => $id,
            "meta"     => $meta,
            "category" => $category,
            "symbol"   => $this->configManager->getSymbol(),
            "price"    => number_format($price),
            "amount"   => $amount,
        )));
        return true;
    }

    private function console(CommandSender $s) {
        $s->sendMessage("§l§8[§cEconomy§eGUI§8]§r §cThis command must be run in-game.");
    }
}
