<?php

/*
 * Delayed container-open task for double-chest GUIs.
 *
 * v1.3.2: this mechanism came back after it was shown experimentally
 * that the client (MCPE 0.14.3/0.15.x) actually needs some time (a few
 * ticks) to process the block update packets (UpdateBlockPacket × 2)
 * and NBT (BlockEntityDataPacket × 2) before it accepts opening the
 * container (ContainerOpenPacket). Without this delay, the client
 * rejects the open immediately (the chest appears above the player's
 * head and disappears, the GUI appears and disappears quickly) even
 * though the server sent all packets successfully.
 */

namespace EconomyGUI\gui;

use pocketmine\scheduler\Task;
use pocketmine\Player;

class OpenContainerTask extends Task {

    /** @var BaseGUI */
    private $gui;

    /** @var Player */
    private $player;

    private $x;
    private $y;
    private $z;

    public function __construct(BaseGUI $gui, Player $player, $x, $y, $z) {
        $this->gui    = $gui;
        $this->player = $player;
        $this->x      = $x;
        $this->y      = $y;
        $this->z      = $z;
    }

    public function onRun($currentTick) {
        if (!$this->player->isOnline()) {
            return; // player left before the delayed task ran
        }

        $this->gui->finishOpen($this->player, $this->x, $this->y, $this->z);
    }
}
