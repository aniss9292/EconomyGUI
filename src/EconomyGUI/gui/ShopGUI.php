<?php

/*
 * Shop GUI - with pagination support
 */

namespace EconomyGUI\gui;

use EconomyGUI\EconomyGUI;
use pocketmine\Player;

class ShopGUI extends BaseGUI {

    protected function getTitleKey() {
        return "shop-title";
    }

    protected function populateOnOpen(Player $who) {
        $this->navContext = "categories";
        $this->currentPage = 0;

        $pendingKey = $this->takePendingCategoryKey();
        if ($pendingKey !== null) {
            $cats = $this->plugin->cfg()->getShopCategories();
            if (isset($cats[$pendingKey])) {
                $cat = $cats[$pendingKey];
                $this->setCurrentCategoryKey($pendingKey);

                // "spawnerz" has mob sub-categories instead of a flat item list
                if (isset($cat["spawner_types"]) && is_array($cat["spawner_types"])) {
                    $this->setCurrentSpawnerTypeKey(null);
                    $this->plugin->fillSpawnerTypes($this, $cat);
                    $who->sendMessage($this->plugin->cfg()->getMessage("shop-opened"));
                    return;
                }

                if (isset($cat["items"])) {
                    $this->plugin->fillShopCategoryItems($this, $cat);
                    $who->sendMessage($this->plugin->cfg()->getMessage("shop-opened"));
                    return;
                }
            }
        }

        $this->plugin->fillShopCategories($this);
        $who->sendMessage($this->plugin->cfg()->getMessage("shop-opened"));
    }
}
