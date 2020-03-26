<?php

namespace SkyWars;

use pocketmine\scheduler\Task as PluginTask;

class Timer extends PluginTask {

    /** @var bool */
    private $tick;

    public function __construct(Main $plugin)
    {
        $this->plugin = $plugin;
        $this->tick = (bool) $plugin->configs["sign.tick"];
    }

    public function onRun(int $tick) : void
    {
        $owner = $this->plugin;

        foreach ($owner->arenas as $arena) {
            $arena->tick();
        }

        if ($this->tick && ($tick % 5 === 0)) {
            $owner->refreshSigns();
        }
    }
}
