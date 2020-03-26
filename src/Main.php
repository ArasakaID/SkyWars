<?php

namespace SkyWars;

use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\tile\Sign;
use pocketmine\utils\TextFormat;
use onebone\economyapi\EconomyAPI;

use SkyWars\utils\Economy;

class Main extends PluginBase {

    /** Plugin Version */
    const SW_VERSION = "0.7dev";

    /** @var Commands */
    private $commands;

    /** @var Arena[] */
    public $arenas = [];

    /** @var array */
    public $signs = [];

    /** @var array */
    public $configs;

    /** @var array */
    public $lang;

    public $kill;

    /** @var Economy|null */
    public $economy;

    /** @var string[] */
    private $player_arenas = [];

    public function onEnable() : void
    {
        @mkdir($this->getDataFolder());

        foreach ($this->getResources() as $resource) {
            $this->saveResource($resource->getFilename());
        }

        //Config files: /SW_configs.yml /SW_lang.yml & for arenas: /arenas/SWname/settings.yml
        $this->configs = array_map(function($value){ return is_string($value) ? TextFormat::colorize($value) : $value; }, yaml_parse_file($this->getDataFolder() . "configs.yml"));
        $this->lang = array_map([TextFormat::class, "colorize"], yaml_parse_file($this->getDataFolder() . "lang.yml"));

        $version = $this->configs["CONFIG_VERSION"] ?? "1st";
        if ($version !== self::SW_VERSION) {
            $this->updatePlugin($version);
            $this->configs["CONFIG_VERSION"] = self::SW_VERSION;
        }

        //Register timer and listener
        $this->getScheduler()->scheduleRepeatingTask(new Timer($this), 20);
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

        //Calls loadArenas() & loadSigns() to loads arenas & signs...
        $this->loadSigns();
        $this->loadArenas();

        $this->getServer()->getCommandMap()->register($this->getName(), new Commands("sw", $this));

        if ($this->configs["reward.winning.players"]) {
            if (!$this->economy->getApiVersion()) {
                $this->getLogger()->warning('Cannot find an economy plugin, the reward feature will be disabled.');
            } else {
                $this->economy = new Economy($this);
            }
        }
    }

    private function updatePlugin(string $old_version) : void
    {
    }

    public function onDisable() : void
    {
        yaml_emit_file($this->getDataFolder() . "signs.yml", $this->signs);
        foreach ($this->arenas as $arena) {
            $arena->stop(true);
        }
    }

    public function loadArenas() : void
    {
        $base_path = $this->getDataFolder() . "arenas/";
        @mkdir($base_path);

        foreach (scandir($base_path) as $dir) {
            $dir = $base_path . $dir;
            $settings_path = $dir . "/settings.yml";

            if (!is_file($settings_path)) {
                continue;
            }

            $arena_info = yaml_parse_file($settings_path);

            $this->arenas[$arena_info["name"]] = new Arena(
                $this,
                $arena_info["name"],
                (int) $arena_info["slot"],
                $arena_info["world"],
                (int) $arena_info["countdown"],
                (int) $arena_info["maxGameTime"],
                (int) $arena_info["void_Y"]
            );
        }
    }

    public function loadSigns() : void
    {
        $signs = yaml_parse_file($this->getDataFolder() . "signs.yml");
        if (!empty($signs)) {
            foreach ($signs as $xyzworld => $arena) {
                [$x, $y, $z, $world] = explode(":", $xyzworld, 4);
                $this->signs[$x . ":" . $y . ":" . $z . ":" . $world] = $arena;
            }
        }
    }

    public function getPlayerArena(Player $player) : ?Arena
    {
        return isset($this->player_arenas[$pid = $player->getId()]) ? $this->arenas[$this->player_arenas[$pid]] : null;
    }

    public function setPlayerArena(Player $player, ?string $arena) : void
    {
        if ($arena === null) {
            unset($this->player_arenas[$player->getId()]);
            return;
        }

        $this->player_arenas[$player->getId()] = $arena;
    }

    public function getArenaFromSign(Position $pos) : ?string
    {
        return $this->signs[$pos->x . ":" . $pos->y . ":" . $pos->z . ":" . $pos->getLevel()->getFolderName()] ?? null;
    }

    public function getNearbySigns(Position $pos, int $radius, &$arena = null) : \Generator
    {
        $pos->x = floor($pos->x);
        $pos->y = floor($pos->y);
        $pos->z = floor($pos->z);

        $level = $pos->getLevel()->getFolderName();

        $minX = $pos->x - $radius;
        $minY = $pos->y - $radius;
        $minZ = $pos->z - $radius;

        $maxX = $pos->x + $radius;
        $maxY = $pos->y + $radius;
        $maxZ = $pos->z + $radius;

        for ($x = $minX; $x <= $maxX; ++$x) {
            for ($y = $minY; $y <= $maxY; ++$y) {
                for ($z = $minZ; $z <= $maxZ; ++$z) {
                    $key =  $x . ":" . $y . ":" . $z . ":" . $level;
                    if (isset($this->signs[$key])) {
                        $arena = $this->signs[$key];
                        yield new Vector3($x, $y, $z);
                    }
                }
            }
        }
    }

    public function setSign(string $arena, Position $pos) : void
    {
        $this->signs[$pos->x . ":" . $pos->y . ":" . $pos->z . ":" . $pos->getLevel()->getFolderName()] = $arena;
    }

    public function deleteSign(Position $pos) : void
    {
        $level = $pos->getLevel();

        $pos = $pos->floor();
        $level->useBreakOn($pos);

        unset($this->signs[$pos->x . ":" . $pos->y . ":" . $pos->z . ":" . $level->getFolderName()]);
    }

    public function deleteAllSigns(?string $arena = null) : int
    {
        $count = 0;

        if ($arena === null) {
            foreach ($this->signs as $arena) {
                $count += $this->deleteAllSigns($arena);
            }
        } else {
            foreach (array_keys($this->signs, $arena, true) as $xyzw) {
                $xyzw = explode(":", $xyzw, 4);

                $server = $this->getServer();
                $server->loadLevel($xyzw[3]);
                $level = $server->getLevelByName($xyzw[3]);

                if ($level !== null) {
                    $this->deleteSign(new Position((int) $xyzw[0], (int) $xyzw[1], (int) $xyzw[2], $level));
                    ++$count;
                }
            }
        }

        return $count;
    }

    public function refreshSigns(?string $arena = null, int $players = 0, int $maxplayers = 0, string $state = TextFormat::WHITE . "Tap to join") : void
    {
        if ($arena === null) {
            foreach (array_unique($this->signs) as $arena) {
                $this->refreshSigns($arena);
            }

            return;
        }

        $server = $this->getServer();

        foreach ($this->signs as $xyzworld => $arena_name) {
            if ($arena_name === $arena) {
                [$x, $y, $z, $world] = explode(":", $xyzworld);

                $level = $server->getLevelByName($world);
                if ($level === null && !$server->loadLevel($level)) {//console error?
                    return;
                }

                $tile = $level->getTileAt($x, $y, $z);
                if ($tile instanceof Sign) {
                    $tile->setText(
                        null,
                        null,
                        TextFormat::GREEN . $players . TextFormat::BOLD . TextFormat::DARK_GRAY . "/" . TextFormat::RESET . TextFormat::GREEN . $maxplayers,
                        $state
                    );
                }
            }
        }
    }

    public function inArena(Player $player) : bool
    {
        foreach ($this->arenas as $arena) {
            if ($arena->inArena($player)) {
                return true;
            }
        }
        return false;
    }

    public function getChestContents() : array//TODO: **rewrite** this and let the owner decide the contents of the chest
    {
        $items = [
            //ARMOR
            "armor" => [
                [
                    Item::LEATHER_CAP,
                    Item::LEATHER_TUNIC,
                    Item::LEATHER_PANTS,
                    Item::LEATHER_BOOTS
                ],
                [
                    Item::GOLD_HELMET,
                    Item::GOLD_CHESTPLATE,
                    Item::GOLD_LEGGINGS,
                    Item::GOLD_BOOTS
                ],
                [
                    Item::CHAIN_HELMET,
                    Item::CHAIN_CHESTPLATE,
                    Item::CHAIN_LEGGINGS,
                    Item::CHAIN_BOOTS
                ],
                [
                    Item::IRON_HELMET,
                    Item::IRON_CHESTPLATE,
                    Item::IRON_LEGGINGS,
                    Item::IRON_BOOTS
                ],
                [
                    Item::DIAMOND_HELMET,
                    Item::DIAMOND_CHESTPLATE,
                    Item::DIAMOND_LEGGINGS,
                    Item::DIAMOND_BOOTS
                ]
            ],

            //WEAPONS
            "weapon" => [
                [
                    Item::WOODEN_SWORD,
                    Item::WOODEN_AXE,
                ],
                [
                    Item::GOLD_SWORD,
                    Item::GOLD_AXE
                ],
                [
                    Item::STONE_SWORD,
                    Item::STONE_AXE
                ],
                [
                    Item::IRON_SWORD,
                    Item::IRON_AXE
                ],
                [
                    Item::DIAMOND_SWORD,
                    Item::DIAMOND_AXE
                ]
            ],

            //FOOD
            "food" => [
                [
                    Item::RAW_PORKCHOP,
                    Item::RAW_CHICKEN,
                    Item::MELON_SLICE,
                    Item::COOKIE
                ],
                [
                    Item::RAW_BEEF,
                    Item::CARROT
                ],
                [
                    Item::APPLE,
                    Item::GOLDEN_APPLE
                ],
                [
                    Item::BEETROOT_SOUP,
                    Item::BREAD,
                    Item::BAKED_POTATO
                ],
                [
                    Item::MUSHROOM_STEW,
                    Item::COOKED_CHICKEN
                ],
                [
                    Item::COOKED_PORKCHOP,
                    Item::STEAK,
                    Item::PUMPKIN_PIE
                ],
            ],

            //THROWABLE
            "throwable" => [
                [
                    Item::BOW,
                    Item::ARROW
                ],
                [
                    Item::SNOWBALL
                ],
                [
                    Item::EGG
                ]
            ],

            //BLOCKS
            "block" => [
                Item::STONE,
                Item::WOODEN_PLANKS,
                Item::COBBLESTONE,
                Item::DIRT
            ],

            //OTHER
            "other" => [
                [
                    Item::WOODEN_PICKAXE,
                    Item::GOLD_PICKAXE,
                    Item::STONE_PICKAXE,
                    Item::IRON_PICKAXE,
                    Item::DIAMOND_PICKAXE
                ],
                [
                    Item::STICK,
                    Item::STRING
                ]
            ]
        ];

        $templates = [];
        for ($i = 0; $i < 10; $i++) {//TODO: understand wtf is the stuff in here doing

            $armorq = mt_rand(0, 1);
            $armortype = $items["armor"][array_rand($items["armor"])];

            $armor1 = [$armortype[array_rand($armortype)], 1];
            if ($armorq) {
                $armortype = $items["armor"][array_rand($items["armor"])];
                $armor2 = [$armortype[array_rand($armortype)], 1];
            } else {
                $armor2 = [0, 1];
            }

            $weapontype = $items["weapon"][array_rand($items["weapon"])];
            $weapon = [$weapontype[array_rand($weapontype)], 1];

            $ftype = $items["food"][array_rand($items["food"])];
            $food = [$ftype[array_rand($ftype)], mt_rand(2, 5)];

            if (mt_rand(0, 1)) {
                $tr = $items["throwable"][array_rand($items["throwable"])];
                if (count($tr) === 2) {
                    $throwable1 = [$tr[1], mt_rand(10, 20)];
                    $throwable2 = [$tr[0], 1];
                } else {
                    $throwable1 = [0, 1];
                    $throwable2 = [$tr[0], mt_rand(5, 10)];
                }
                $other = [0, 1];
            } else {
                $throwable1 = [0, 1];
                $throwable2 = [0, 1];
                $ot = $items["other"][array_rand($items["other"])];
                $other = [$ot[array_rand($ot)], 1];
            }

            $block = [$items["block"][array_rand($items["block"])], 64];

            $contents = [
                $armor1,
                $armor2,
                $weapon,
                $food,
                $throwable1,
                $throwable2,
                $block,
                $other
            ];
            shuffle($contents);

            $fcontents = [
                mt_rand(0, 1) => array_shift($contents),
                mt_rand(2, 4) => array_shift($contents),
                mt_rand(5, 9) => array_shift($contents),
                mt_rand(10, 14) => array_shift($contents),
                mt_rand(15, 16) => array_shift($contents),
                mt_rand(17, 19) => array_shift($contents),
                mt_rand(20, 24) => array_shift($contents),
                mt_rand(25, 26) => array_shift($contents),
           ];

            $templates[] = $fcontents;
        }

        shuffle($templates);
        return $templates;
    }

    public function sendDeathMessage(Player $player) : void
    {
        $arena = $this->getPlayerArena($player);
        $status = "[" . ($arena->getSlot(true) - 1) . "/" . $arena->getSlot() . "]";

        $last_cause_ev = $player->getLastDamageCause();
        switch ($last_cause_ev->getCause()) {
            case EntityDamageEvent::CAUSE_ENTITY_ATTACK:
                $damager = $last_cause_ev->getDamager();
                $message = strtr($this->lang["death.player"], [
                    '{COUNT}' => $status,
                    '{KILLER}' => $damager instanceof Player ? $damager->getDisplayName() : ($damager instanceof Living ? $damager->getName() : $damager->getNameTag()),
                    '{PLAYER}' => $player->getDisplayName()
                ]);
                break;
            case EntityDamageEvent::CAUSE_PROJECTILE:
                $damager = $last_cause_ev->getDamager();
                $message = strtr($this->lang["death.arrow"], [
                    '{COUNT}' => $status,
                    '{KILLER}' => $damager instanceof Player ? $damager->getDisplayName() : ($damager instanceof Living ? $damager->getName() : $damager->getNameTag()),
                    '{PLAYER}' => $player->getDisplayName()
                ]);
                break;
            case EntityDamageEvent::CAUSE_VOID:
                $message = strtr($this->lang["death.void"], [
                    '{COUNT}' => $status,
                    '{PLAYER}' => $player->getDisplayName()
                ]);
                break;
            case EntityDamageEvent::CAUSE_LAVA:
                $message = strtr($this->lang["death.lava"], [
                    '{COUNT}' => $status,
                    '{PLAYER}' => $player->getDisplayName()
                ]);
                break;
            default:
                $message = strtr($this->lang["game.left"], [
                    '{COUNT}' => $status,
                    '{PLAYER}' => $player->getDisplayName()
                ]);
                break;
        }

        $this->getServer()->broadcastMessage($message, $player->getLevel()->getPlayers());
    }

}
