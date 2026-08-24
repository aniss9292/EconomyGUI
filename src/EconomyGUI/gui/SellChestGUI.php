<?php

/*
 * Sell Chest GUI - Put items to sell (54 slots)
 * On close: sell directly, return unsellable
 */

namespace EconomyGUI\gui;

use EconomyGUI\EconomyGUI;
use pocketmine\Player;
use pocketmine\item\Item;

class SellChestGUI extends BaseGUI {

    protected function getTitleKey() {
        return "sell-title";
    }

    protected function populateOnOpen(Player $who) {
        // Empty chest - player puts items in
        // No navigation buttons needed for sell chest
        // FIXED: the "sell-opened" message was removed per user request —
        // it used to show automatically when the chest was opened ("Put
        // items to sell, then close the chest"). The sell chest no longer
        // shows any message on open; the only message is the profit summary
        // on close.
    }

    /**
     * Called when the player closes the chest.
     * Process all items: sell what's sellable, return what's not.
     */
    public function onClose(Player $who) {
        // FIXED: same idempotency guard used in BaseGUI::onClose(), but
        // applied here specifically before processSellItems(), so it
        // doesn't sell the player's items twice or send a duplicate
        // "sell-no-items"/profit summary message if onClose() gets called
        // twice for the same player (e.g. a manual call via removeWindow()
        // followed by the client's automatic onInventoryClose()).
        if (!isset($this->viewers[spl_object_hash($who)])) {
            return;
        }

        $this->processSellItems($who);
        parent::onClose($who);
    }

    /**
     * Process items in the chest:
     * - Items matching sell config → sold, money added, items consumed
     * - Items NOT matching → returned to player inventory
     * - Show tip with total earnings
     */
    private function processSellItems(Player $player) {
        $plugin = $this->plugin;
        $eco    = $plugin->getEconomyAPI();
        $cfg    = $plugin->cfg();
        $sym    = $cfg->getSymbol();

        if ($eco === null) {
            $player->sendMessage("§cEconomyAPI not found! Cannot sell items.");
            $this->returnAllItems($player);
            return;
        }

        $sellPriceMap = $plugin->getSellPriceMap();
        $totalEarned  = 0;
        $soldCount    = 0;
        $soldItems    = array();
        $returned     = array();

        foreach ($this->getContents() as $slot => $item) {
            if ($item === null || $item->getId() === Item::AIR) continue;

            $id   = $item->getId();
            $meta = $item->getDamage();
            $key  = $id . ":" . $meta;

            if (isset($sellPriceMap[$key])) {
                // This item can be sold
                $pricePerUnit = $sellPriceMap[$key];
                $count        = $item->getCount();
                $payout       = (int)round($pricePerUnit * $count);

                $totalEarned += $payout;
                $soldCount   += $count;
                $soldItems[] = clone $item;
                // Item is consumed - NOT returned to player
            } else {
                // Cannot sell - return to player
                $returned[] = clone $item;
            }
        }

        // Clear the chest contents (all items are now processed)
        $this->clearAll();

        // Add money for sold items
        if ($totalEarned > 0) {
            $eco->addMoney($player->getName(), $totalEarned);
        }

        // Return unsellable items to player
        foreach ($returned as $item) {
            if ($player->getInventory()->canAddItem($item)) {
                $player->getInventory()->addItem($item);
            } else {
                // Drop at player location if inventory full
                $player->getLevel()->dropItem($player, $item);
            }
        }

        // Show tip with results
        if ($soldCount > 0) {
            // Store for undo
            $name = strtolower($player->getName());
            $plugin->setLastSell($name, array(
                "items" => $soldItems,
                "money" => $totalEarned,
                "time"  => time(),
            ));

            // FIXED: sendTip was replaced with sendMessage per user request
            // — sendTip appears above the screen (action bar) for just one
            // second then disappears quickly, so it might not be clearly
            // noticed. Now the profit summary appears as a permanent chat
            // message when the chest is closed.
            $player->sendMessage(
                $cfg->getMessage("sell-chest-success", array(
                    "amount" => $soldCount,
                    "symbol" => $sym,
                    "price"  => number_format($totalEarned),
                ))
            );

            // Play tick sound
            $plugin->playTickSound($player, "sell");

            $cfg->logTransaction("SELL_CHEST", strtolower($player->getName()), $soldCount, "various", $totalEarned);
        } else {
            $player->sendTip($cfg->getMessage("sell-no-items"));
            $plugin->playTickSound($player, "error");
        }

        if (count($returned) > 0) {
            $player->sendMessage(
                $cfg->getMessage("sell-returned", array(
                    "count" => count($returned),
                ))
            );
        }
    }

    /**
     * Return all items to player (used when EconomyAPI is missing)
     */
    private function returnAllItems(Player $player) {
        foreach ($this->getContents() as $slot => $item) {
            if ($item === null || $item->getId() === Item::AIR) continue;
            if ($player->getInventory()->canAddItem($item)) {
                $player->getInventory()->addItem($item);
            } else {
                $player->getLevel()->dropItem($player, $item);
            }
        }
        $this->clearAll();
    }
}
