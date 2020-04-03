<?php

namespace SkyWars;

use pocketmine\Server;
use pocketmine\block\Block;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockPlaceEvent;

use pocketmine\level\Position;
use pocketmine\level\Location;

use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;

use jojoe77777\FormAPI;
use jojoe77777\FormAPI\SimpleForm;

class EventListener implements Listener {

    /** @var SWmain */
    private $plugin;

    public function __construct(Main $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onSignChange(SignChangeEvent $event) : void
    {
        $player = $event->getPlayer();
        if (!$player->isOp() || $event->getLine(0) !== 'sw') {
            return;
        }

        $arena = $event->getLine(1);
        if (!isset($this->plugin->arenas[$arena])) {
            $player->sendMessage(TextFormat::RED . "This arena doesn't exist, try " . TextFormat::GOLD . "/sw create");
            return;
        }

        if (in_array($arena, $this->plugin->signs)) {
            $player->sendMessage(TextFormat::RED . "A sign for this arena already exist, try " . TextFormat::GOLD . "/sw signdelete");
            return;
        }

        $block = $event->getBlock();
        $level = $block->getLevel();
        $level_name = $level->getFolderName();

        foreach ($this->plugin->arenas as $name => $arena_instance) {
            if ($arena_instance->getWorld() === $level_name) {
                $player->sendMessage(TextFormat::RED . "You can't place the join sign inside arenas.");
                return;
            }
        }

        if (!$this->plugin->arenas[$arena]->checkSpawns()) {
            $player->sendMessage(TextFormat::RED . "You haven't configured all the spawn points for this arena, use " . TextFormat::YELLOW . "/sw setspawn");
            return;
        }

        $this->plugin->setSign($arena, $block);
        $this->plugin->refreshSigns($arena);

        $event->setLine(0, $this->plugin->configs["1st_line"]);
        $event->setLine(1, str_replace("{SWNAME}", $this->plugin->arenas[$arena]->getName(), $this->plugin->configs["2nd_line"]));

        $player->sendMessage(TextFormat::GREEN . "Successfully created join sign for '" . TextFormat::YELLOW . $arena . TextFormat::GREEN . "'!");
    }

    public function onInteract(PlayerInteractEvent $event) : void
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        $arena = $this->plugin->getPlayerArena($player);
        if ($item->getCustomName() == "§r§cQuit from arena" && $item->getId() == 355){
            $arena->closePlayer($player);
        }
        if ($item->getCustomName() == "§r§aKit selector" && $item->getId() == 54){
            $this->kitForm($player);
        }
        if (($block->getId() === Block::SIGN_POST || $block->getId() === Block::WALL_SIGN) && $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            $arena = $this->plugin->getArenaFromSign($block);
            if ($arena !== null) {
                $player = $event->getPlayer();
                if ($this->plugin->getPlayerArena($player) === null) {
                    $this->plugin->arenas[$arena]->join($player);
                }
            }
        }
    }


    public function onLevelChange(EntityLevelChangeEvent $event) : void
    {//no fucking clue why this check exists
        $player = $event->getEntity();
        if ($player instanceof Player && $this->plugin->getPlayerArena($player) !== null) {
            $event->setCancelled();
        }
    }

    public function onTeleport(EntityTeleportEvent $event) : void
    {//no fucking clue why this check exists
        $player = $event->getEntity();
        if ($player instanceof Player && $this->plugin->getPlayerArena($player) !== null && $event->getFrom()->distanceSquared($event->getTo()) >= 20) {
            $event->setCancelled();
        }
    }

    public function onDropItem(PlayerDropItemEvent $event) : void
    {
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);

        if ($arena !== null) {
            $type = $arena->inArena($player);
            if ($type === Arena::PLAYER_SPECTATING || ($type === Arena::PLAYER_PLAYING && !$this->plugin->configs["player.drop.item"])) {
                $event->setCancelled();
            }
        }

        $item = $player->getInventory()->getItemInHand();
        if ($item->getCustomName() == "§r§cQuit from arena") {
            $event->setCancelled();
        }
        if ($item->getCustomName() == "§r§aKit selector") {
	    $player->getInventory()->setHeldItemIndex(1);
            $event->setCancelled();
        }
    }

    public function onPickUp(InventoryPickupItemEvent $event) : void
    {
        $player = $event->getInventory()->getHolder();
        if ($player instanceof Player && ($arena = $this->plugin->getPlayerArena($player)) !== null && $arena->inArena($player) === Arena::PLAYER_SPECTATING) {
            $event->setCancelled();
        }
    }

    public function onItemHeld(PlayerItemHeldEvent $event) : void
    {
        $player = $event->getPlayer();
        if($event->getItem()->getId() === Item::COMPASS && $event->getItem()->getCustomName() === "§r§aSpectator"){
            $this->spectatorForm($player);
        }
    }

    public function onMove(PlayerMoveEvent $event) : void
    {
        $from = $event->getFrom();
        $to = $event->getTo();

        $player = $event->getPlayer();

        if (floor($from->x) !== floor($to->x) || floor($from->z) !== floor($to->z) || floor($from->y) !== floor($from->y)) {//moved a block
            $arena = $this->plugin->getPlayerArena($player);
            if ($arena !== null) {
                if ($arena->GAME_STATE === Arena::STATE_COUNTDOWN) {
                    $event->setCancelled();
                } elseif ($arena->void >= floor($to->y)) {
                    $player->attack(new EntityDamageEvent($player, EntityDamageEvent::CAUSE_VOID, 100));
                }
                return;
            }

            if ($this->plugin->configs["sign.knockBack"]) {
                foreach ($this->plugin->getNearbySigns($to, $this->plugin->configs["knockBack.radius.from.sign"]) as $pos) {
                    $player->knockBack($player, 0, $from->x - $pos->x, $from->z - $pos->z, $this->plugin->configs["knockBack.intensity"] / 5);
                    break;
                }
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event) : void
    {
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);

        if ($arena !== null) {
            $arena->closePlayer($player);
        }
    }

    public function onDeath(PlayerDeathEvent $event) : void
    {
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);

        if ($arena !== null) {
            $this->plugin->sendDeathMessage($player);
            $arena->closePlayer($player);
            $event->setDeathMessage("");

            if (!$this->plugin->configs["drops.on.death"]) {
                $event->setDrops([]);
            }
            $player->getInventory()->setItem(4, Item::get(345)->setCustomName("§r§aSpectator"));
        }
    }

    /**
     * @param EntityDamageEvent $event
     * @priority HIGH
     * @ignoreCancelled true
     */
    public function onDamage(EntityDamageEvent $event) : void
    {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            $arena = $this->plugin->getPlayerArena($entity);
            if ($arena !== null) {
                if (
                    $arena->inArena($entity) !== Arena::PLAYER_PLAYING ||
                    $arena->GAME_STATE === Arena::STATE_COUNTDOWN ||
                    $arena->GAME_STATE === Arena::STATE_NOPVP ||
                    in_array($event->getCause(), $this->plugin->configs["damage.cancelled.causes"])
                ) {
                    $event->setCancelled();
                    return;
                }

                if ($event instanceof EntityDamageByEntityEvent && ($damager = $event->getDamager()) instanceof Player) {
                    if ($arena->inArena($damager) !== Arena::PLAYER_PLAYING) {
                        $event->setCancelled();
                        return;
                    }
                }

                if ($this->plugin->configs["death.spectator"]) {
                    if (($entity->getHealth() - $event->getFinalDamage()) <= 0) {
                        $entity->addTitle("§c§lYOU DIED!", "§eDont give up!");
                        $event->setCancelled();
                        $this->plugin->sendDeathMessage($entity);

                        if ($this->plugin->configs["drops.on.death"]) {
                            $entity->getInventory()->dropContents($entity->getLevel(), $entity->asVector3());
                        }

                        $arena->closePlayer($entity, false, true);
                    }
                }
            }
        }
    }

    public function onRespawn(PlayerRespawnEvent $event) : void
    {
        if ($this->plugin->configs["always.spawn.in.defaultLevel"]) {
            $event->setRespawnPosition($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
        }

        if ($this->plugin->configs["clear.inventory.on.respawn&join"]) {
            $event->getPlayer()->getInventory()->clearAll();
        }

        if ($this->plugin->configs["clear.effects.on.respawn&join"]) {
            $event->getPlayer()->removeAllEffects();
        }
    }

    public function onBreak(BlockBreakEvent $event) : void
    {
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);

        if ($arena !== null && $arena->inArena($player) !== Arena::PLAYER_PLAYING) {
            $event->setCancelled();
        }

        $block = $event->getBlock();
        $sign = $this->plugin->getArenaFromSign($block);
        if ($sign !== null) {
            if (!$player->isOp()) {
                $event->setCancelled();
                return;
            }

            $this->plugin->deleteSign($block);
            $player->sendMessage(TextFormat::GREEN . "Removed join sign for arena '" . TextFormat::YELLOW . $arena . TextFormat::GREEN . "'!");
        }
    }

    public function onPlace(BlockPlaceEvent $event) : void
    {
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);

        if ($arena !== null && $arena->inArena($player) !== Arena::PLAYER_PLAYING) {
            $event->setCancelled();
        }
    }


    public function onCommand(PlayerCommandPreprocessEvent $event) : void
    {
        $command = $event->getMessage();
        if ($command{0} === "/") {
            $player = $event->getPlayer();
            if ($this->plugin->getPlayerArena($player) !== null) {
                if (in_array(strtolower(explode(" ", $command, 2)[0]), $this->plugin->configs["banned.commands.while.in.game"])) {
                    $player->sendMessage($this->plugin->lang["banned.command.msg"]);
                    $event->setCancelled();
                }
            }
        }
    }
    
    public function spectatorForm(Player $player){
		$form = new SimpleForm(function (Player $player, $data){
		$result = $data;
		if($result === null){
			return true;
			}
			switch($result){
                case 0:
                break;
                case 1:
                    $arena = $this->plugin->getPlayerArena($player);
                    $arena->closePlayer($player);
                break;
                case 2:
                    $player->getServer()->dispatchCommand($player, "report");
                break;
			}
		});					
		$form->setTitle("Spectator");
        $form->addButton("Resume Spectator");
        $form->addButton("Back To Lobby");
        $form->addButton("Report Player");
		$form->sendToPlayer($player);
    }
    
    public function kitForm(Player $player){
		$form = new SimpleForm(function (Player $player, $data){
		$result = $data;
		if($result === null){
			return true;
			}
			switch($result){
                case 0:
                    if($player->hasPermission("sw.kit.armorer")) {
                        $player->sendMessage("§eYou have used the §aArmorer ekit");
                        $player->getInventory()->removeItem(Item::get(54, 0, 1));
                        $inv = $player->getArmorInventory();
                        $inv->setHelmet(Item::get(Item::GOLDEN_HELMET));
                        $inv->setChestplate(Item::get(Item::GOLDEN_CHESTPLATE));
                        $inv->setLeggings(Item::get(Item::GOLDEN_LEGGINGS));
                        $inv->setBoots(Item::get(Item::GOLDEN_BOOTS));
                    } else {
                        $player->sendMessage("§cYou have not bought this kit");
                    }
                break;
                case 1:
                    if($player->hasPermission("sw.kit.blacksmith")){
                        $player->getInventory()->removeItem(Item::get(54, 0, 1));
                        $player->sendMessage("§eYou have used the §aBlacksmith §ekit");
                        $inv = $player->getArmorInventory();
                        $inv->setHelmet(Item::get(Item::IRON_HELMET));
                        $inv->setChestplate(Item::get(Item::IRON_CHESTPLATE));
                        $inv->setLeggings(Item::get(Item::IRON_LEGGINGS));
                        $inv->setBoots(Item::get(Item::IRON_BOOTS));
                    } else {
                        $player->sendMessage("§cYou have not bought this kit");
                    }
                break;
                case 2:
                    if($player->hasPermission("sw.kit.archer")){
                        $player->getInventory()->removeItem(Item::get(54, 0, 1));
                        $player->sendMessage("§eYou have used the §aArcher §ekit");
                        $player->getInventory()->addItem(Item::get(261, 0, 1));
                        $player->getInventory()->addItem(Item::get(262, 0, 32));
                    } else {
                        $player->sendMessage("§cYou have not bought this kit");
                    }
                break;
                case 3:
                    if($player->hasPermission("sw.kit.fighter")){
                        $player->getInventory()->removeItem(Item::get(54, 0, 1));
                        $player->sendMessage("§eYou have used the §aArcher §ekit");
                        $player->getInventory()->addItem(Item::get(Item::DIAMOND_SWORD));
                        $player->getInventory()->addItem(Item::get(322, 0, 3));
                    } else {
                        $player->sendMessage("§cYou have not bought this kit");
                    }
                break;
			}
		});					
		$form->setTitle("Kit");
        $form->addButton("Armorer");
        $form->addButton("Blacksmith");
        $form->addButton("Archer");
        $form->addButton("Fighter");
		$form->sendToPlayer($player);
	}
}
