<?php

/*
 * Delayed container-open task for double-chest GUIs.
 *
 * v1.3.2: هاذ الآلية رجعت بعد ما تبين تجريبيًا أن الكلاينت (MCPE
 * 0.14.3/0.15.x) يحتاج فعليًا فرصة زمنية (كذا تيك) باش يستوعب حزم
 * تحديث البلوك (UpdateBlockPacket × 2) والـNBT (BlockEntityDataPacket
 * × 2) قبل ما يقبل يفتح الحاوية (ContainerOpenPacket). بلا هاذ
 * التأخير، الكلاينت كيرفض الفتح فورًا (الصندوق يبان فوق راس اللاعب
 * ويختفي، الـGUI يبان ويختفي بسرعة) حتى لو السيرفر رسل كل الحزم بنجاح.
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
