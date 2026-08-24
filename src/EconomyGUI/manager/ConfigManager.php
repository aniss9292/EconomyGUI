<?php

namespace EconomyGUI\manager;

use pocketmine\utils\Config;

class ConfigManager {

    private $dataFolder;
    private $mainConfig;
    private $shopConfig;
    private $sellConfig;

    public function __construct($dataFolder) {
        $this->dataFolder = $dataFolder;
        $this->reload();
    }

    public function reload() {
        $this->mainConfig = new Config($this->dataFolder . "config.yml", Config::YAML);
        $this->shopConfig = new Config($this->dataFolder . "shop.yml",   Config::YAML);
        $this->sellConfig = new Config($this->dataFolder . "sell.yml",   Config::YAML);
    }

    public function get($key, $default = null) {
        return $this->mainConfig->get($key, $default);
    }

    public function getSymbol() {
        return (string)$this->get("currency-symbol", "$");
    }

    public function getMessage($key, array $replace = array()) {
        $messages = (array)$this->get("messages", array());
        $msg = isset($messages[$key]) ? $messages[$key] : "§cMissing message key: {$key}";
        foreach ($replace as $ph => $val) {
            $msg = str_replace("{" . $ph . "}", (string)$val, $msg);
        }
        return $msg;
    }


    public function getShopCategories() {
        $raw = $this->shopConfig->get("categories", array());
        return is_array($raw) ? $raw : array();
    }

    public function shopCategoryExists($key) {
        $cats = $this->getShopCategories();
        return isset($cats[strtolower($key)]);
    }

    public function addShopCategory($key, $iconId, $displayName) {
        $key  = strtolower($key);
        $cats = $this->getShopCategories();
        if (isset($cats[$key])) return false;
        $cats[$key] = array(
            "name"  => $displayName,
            "icon"  => (int)$iconId,
            "items" => array(),
        );
        $this->shopConfig->set("categories", $cats);
        $this->shopConfig->save();
        return true;
    }

    public function removeShopCategory($key) {
        $key  = strtolower($key);
        $cats = $this->getShopCategories();
        if (!isset($cats[$key])) return false;
        unset($cats[$key]);
        $this->shopConfig->set("categories", $cats);
        $this->shopConfig->save();
        return true;
    }

    public function addShopItem($id, $meta, $name, $price, $amount, $category, $sellPrice = null, $spawnerType = null, $spawnerTier = null) {
        $category = strtolower($category);
        $cats     = $this->getShopCategories();

        if (!isset($cats[$category])) {
            $cats[$category] = array(
                "name"  => "§r§e" . ucfirst($category),
                "icon"  => (int)$this->get("default-category-icon", 54),
                "items" => array(),
            );
        }

        $entry = array(
            "id"     => (int)$id,
            "meta"   => (int)$meta,
            "name"   => $name,
            "price"  => (int)$price,
            "amount" => (int)$amount,
        );
        if ($sellPrice !== null) $entry["sell"] = (int)$sellPrice;

        // Optional: special identifier for SmartSpawner spawners (all
        // spawners share id:meta=52:0, so the real distinction between
        // types/tiers is done via these two fields instead of id/meta).
        if ($spawnerType !== null) $entry["spawner_type"] = (string)$spawnerType;
        if ($spawnerTier !== null) $entry["spawner_tier"] = (string)$spawnerTier;

        $cats[$category]["items"][] = $entry;
        $this->shopConfig->set("categories", $cats);
        $this->shopConfig->save();
    }

    public function removeShopItem($id, $meta, $category = null) {
        $cats    = $this->getShopCategories();
        $removed = 0;

        foreach ($cats as $key => $cat) {
            if ($category !== null && strtolower($category) !== $key) continue;
            if (!isset($cat["items"])) continue;
            $filtered = array();
            foreach ($cat["items"] as $entry) {
                if ((int)(isset($entry["id"]) ? $entry["id"] : -1) === (int)$id
                    && (int)(isset($entry["meta"]) ? $entry["meta"] : -1) === (int)$meta) {
                    $removed++;
                } else {
                    $filtered[] = $entry;
                }
            }
            $cats[$key]["items"] = $filtered;
        }

        $this->shopConfig->set("categories", $cats);
        $this->shopConfig->save();
        return $removed;
    }


    public function getSellCategories() {
        $raw = $this->sellConfig->get("categories", array());
        return is_array($raw) ? $raw : array();
    }

    public function sellCategoryExists($key) {
        $cats = $this->getSellCategories();
        return isset($cats[strtolower($key)]);
    }

    public function addSellCategory($key, $iconId, $displayName) {
        $key  = strtolower($key);
        $cats = $this->getSellCategories();
        if (isset($cats[$key])) return false;
        $cats[$key] = array(
            "name"  => $displayName,
            "icon"  => (int)$iconId,
            "items" => array(),
        );
        $this->sellConfig->set("categories", $cats);
        $this->sellConfig->save();
        return true;
    }

    public function removeSellCategory($key) {
        $key  = strtolower($key);
        $cats = $this->getSellCategories();
        if (!isset($cats[$key])) return false;
        unset($cats[$key]);
        $this->sellConfig->set("categories", $cats);
        $this->sellConfig->save();
        return true;
    }


    public function addSellItem($id, $meta, $name, $price, $amount, $category) {
        $category = strtolower($category);
        $cats     = $this->getSellCategories();

        if (!isset($cats[$category])) {
            $cats[$category] = array(
                "name"  => "§r§e" . ucfirst($category),
                "icon"  => (int)$this->get("default-category-icon", 54),
                "items" => array(),
            );
        }

        $cats[$category]["items"][] = array(
            "id"     => (int)$id,
            "meta"   => (int)$meta,
            "name"   => $name,
            "price"  => (int)$price,
            "amount" => (int)$amount,
        );

        $this->sellConfig->set("categories", $cats);
        $this->sellConfig->save();
    }

    public function removeSellItem($id, $meta, $category = null) {
        $cats    = $this->getSellCategories();
        $removed = 0;

        foreach ($cats as $key => $cat) {
            if ($category !== null && strtolower($category) !== $key) continue;
            if (!isset($cat["items"])) continue;
            $filtered = array();
            foreach ($cat["items"] as $entry) {
                if ((int)(isset($entry["id"]) ? $entry["id"] : -1) === (int)$id
                    && (int)(isset($entry["meta"]) ? $entry["meta"] : -1) === (int)$meta) {
                    $removed++;
                } else {
                    $filtered[] = $entry;
                }
            }
            $cats[$key]["items"] = $filtered;
        }

        $this->sellConfig->set("categories", $cats);
        $this->sellConfig->save();
        return $removed;
    }


    public function getBuySlots() {
        $default = array(
            array("slot" => 11, "amount" => 1),
            array("slot" => 13, "amount" => 10),
            array("slot" => 15, "amount" => 64),
        );
        return (array)$this->get("buy-slots", $default);
    }

    public function getSellMultipliers() {
        $default = array(
            array("slot" => 11, "multiplier" => 1),
            array("slot" => 13, "multiplier" => 2),
            array("slot" => 15, "multiplier" => 5),
        );
        return (array)$this->get("sell-multipliers", $default);
    }

    public function logTransaction($type, $player, $amount, $item, $price) {
        if (!$this->get("log-transactions", false)) return;
        $line = date("[Y-m-d H:i:s]") . " [$type] {$player} | {$amount}x {$item} | {$price} coins\n";
        file_put_contents($this->dataFolder . "transactions.log", $line, FILE_APPEND);
    }
}
