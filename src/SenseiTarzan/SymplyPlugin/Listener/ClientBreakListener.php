<?php

/*
 *
 *            _____ _____         _      ______          _____  _   _ _____ _   _  _____
 *      /\   |_   _|  __ \       | |    |  ____|   /\   |  __ \| \ | |_   _| \ | |/ ____|
 *     /  \    | | | |  | |______| |    | |__     /  \  | |__) |  \| | | | |  \| | |  __
 *    / /\ \   | | | |  | |______| |    |  __|   / /\ \ |  _  /| . ` | | | | . ` | | |_ |
 *   / ____ \ _| |_| |__| |      | |____| |____ / ____ \| | \ \| |\  |_| |_| |\  | |__| |
 *  /_/    \_\_____|_____/       |______|______/_/    \_\_|  \_\_| \_|_____|_| \_|\_____|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author AID-LEARNING
 * @link https://github.com/AID-LEARNING
 *
 */

declare(strict_types=1);

namespace SenseiTarzan\SymplyPlugin\Listener;

use pocketmine\block\Air;
use pocketmine\world\sound\BlockBreakSound;
use SenseiTarzan\SymplyPlugin\Behavior\Blocks\IBlockCustom;
use SenseiTarzan\SymplyPlugin\Behavior\Items\ICustomItem;
use pocketmine\block\Block;
use pocketmine\event\EventPriority;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerBlockAction;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionStopBreak;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionWithBlockInfo;
use pocketmine\player\Player;
use ReflectionProperty;
use SenseiTarzan\ExtraEvent\Class\EventAttribute;
use SenseiTarzan\SymplyPlugin\Player\BlockBreakRequest;
use SenseiTarzan\SymplyPlugin\Utils\BlockUtils;
use WeakMap;
use function array_push;
use function count;
use function floor;

class ClientBreakListener
{
	/** @phpstan-var WeakMap<NetworkSession, BlockBreakRequest> */
	private WeakMap $breaks;

	const MAX_DISTANCE_BREAK = 16 ** 2;

	public function __construct()
	{
		$this->breaks = new WeakMap();
	}

	#[EventAttribute(EventPriority::MONITOR)]
	public function onDataReceive(DataPacketReceiveEvent $event) : void
	{
		$player = ($session = $event->getOrigin())->getPlayer();
		if ($player === null || $player->isCreative())
			return;
		$packet = $event->getPacket();
		if($packet instanceof PlayerAuthInputPacket) {
			$cancel = false;
			$blockActions = $packet->getBlockActions();
			if ($blockActions !== null) {
				if (count($blockActions) > 100) {
					$session->getLogger()->debug("PlayerAuthInputPacket contains " . count($blockActions) . " block actions, dropping");
					return;
				}
				/**
				 * @var int $k
				 * @var PlayerBlockAction $blockAction
				 */
				foreach ($blockActions as $index => $blockAction) {
					$action = $blockAction->getActionType();
					if ($blockAction instanceof PlayerBlockActionWithBlockInfo) {
						if ($action === PlayerAction::START_BREAK) {
							$vector3 = new Vector3($blockAction->getBlockPosition()->getX(), $blockAction->getBlockPosition()->getY(), $blockAction->getBlockPosition()->getZ());
							$block = $player->getWorld()->getBlock($vector3);
							if ($block->getBreakInfo()->breaksInstantly())
								continue;
							$speed = BlockUtils::getDestroyRate($player, $block);
							$this->breaks->offsetSet($session, new BlockBreakRequest($player->getWorld(), $vector3, $speed));

							if(!$player->attackBlock($vector3, $blockAction->getFace()))
							{
								$this->onFailedBlockAction($session, $player, $vector3, $blockAction->getFace());
							}else {
								$player->getWorld()->broadcastPacketToViewers(
									$vector3,
									LevelEventPacket::create(LevelEvent::BLOCK_START_BREAK, (int) floor($speed * 65535.0), $vector3)
								);
							}
							unset($blockActions[$index]);
						} elseif ($action === PlayerAction::CRACK_BREAK) {
							if ($this->breaks->offsetExists($session)) {
								$vector3 = new Vector3($blockAction->getBlockPosition()->getX(), $blockAction->getBlockPosition()->getY(), $blockAction->getBlockPosition()->getZ());
								$block = $player->getWorld()->getBlock($vector3);
								$breakRequest = $this->breaks->offsetGet($session);
								if ($vector3->distanceSquared($breakRequest->getOrigin()) > self::MAX_DISTANCE_BREAK) {
									unset($this->breaks[$session]);
									continue;
								}
								if ($breakRequest->addTick(BlockUtils::getDestroyRate($player, $block)) >= 1) {
									$player->breakBlock($vector3);
									unset($this->breaks[$session]);
								}
							}
							unset($blockActions[$index]);
						} elseif ($blockAction->getActionType() === PlayerAction::ABORT_BREAK) {
							$vector3 = new Vector3($blockAction->getBlockPosition()->getX(), $blockAction->getBlockPosition()->getY(), $blockAction->getBlockPosition()->getZ());
							if ($this->breaks->offsetExists($session)) {
								$player->stopBreakBlock($vector3);
								unset($this->breaks[$session]);
							}
							unset($blockActions[$index]);
						} elseif($blockAction->getActionType() === PlayerAction::STOP_BREAK) $event->cancel();
					} elseif ($blockAction instanceof PlayerBlockActionStopBreak) {
						if ($this->breaks->offsetExists($session)) {
							unset($this->breaks[$session]);
						}
					}
				}
				(new ReflectionProperty($packet, "blockActions"))->setValue($packet, $blockActions);
			}
		}/*else if($packet instanceof  InventoryTransactionPacket){
			$data = $packet->trData;
			if ($data instanceof UseItemTransactionData && $data->getActionType() === UseItemTransactionData::ACTION_BREAK_BLOCK){
				if ($this->breaks->offsetExists($session)){
					$breakRequest = $this->breaks->offsetGet($session);
					$blockPos = $data->getBlockPosition();
					$vBlockPos = new Vector3($blockPos->getX(), $blockPos->getY(), $blockPos->getZ());
					if ($vBlockPos->distanceSquared($breakRequest->getOrigin()) > self::MAX_DISTANCE_BREAK) {
						$this->breaks->offsetUnset($session);
						return;
					}
					if(floor($breakRequest->getStart()) < 1) {
						$this->onFailedBlockAction($session, $player, $vBlockPos, null);
							$event->cancel();
					}
				}
			}
		}*/
	}

	/**
	 * Internal function used to execute rollbacks when an action fails on a block.
	 */
	private function onFailedBlockAction(NetworkSession $session, Player $player, Vector3 $blockPos, ?int $face) : void{
		if($blockPos->distanceSquared($player->getLocation()) < 10000){
			$blocks = $blockPos->sidesArray();
			if($face !== null){
				$sidePos = $blockPos->getSide($face);

				/** @var Vector3[] $blocks */
				array_push($blocks, ...$sidePos->sidesArray()); //getAllSides() on each of these will include $blockPos and $sidePos because they are next to each other
			}else{
				$blocks[] = $blockPos;
			}
			foreach($player->getWorld()->createBlockUpdatePackets($blocks) as $packet){
				$session->sendDataPacket($packet);
			}
		}
	}
}
