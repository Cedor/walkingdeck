<?php

namespace Bga\Games\TheWalkingDeck;

require_once(APP_GAMEMODULE_PATH . "module/table/table.game.php");

class TWDDeck
{
  protected $game;

  public function __construct($game)
  {
    $this->game = $game;
  }

  private function getExtendedCardInfo(int $type, int $type_arg): array
  {
    if ($type === 1) {
      // protagonist
      $cardInfo = $this->game->getObjectListFromDB(
        "SELECT `card_name`, `losscon`
          FROM `card_info` JOIN `protagonist_info` ON `card_info`.`info_id` = `protagonist_info`.`info_id`
          WHERE `card_info`.`info_id` = `protagonist_info`.`info_id`
          AND `card_info`.`card_type` = $type
          AND `card_info`.`card_type_arg` = $type_arg"
      );
      if ($cardInfo) {
        $cardInfo = $cardInfo[0];
      } else {
        $cardInfo = array();
      }
    } else { // type == 2 or 3
      // rural and urban
      $cardInfo = $this->game->getObjectListFromDB(
        "SELECT `card_name`, `is_zombie`, `is_character`, `consequence_black`, `consequence_white`, `consequence_grey`, `special_draw`,`weakness_1`, `weakness_2`, `weakness_3`, `wounds`
          FROM `card_info` LEFT JOIN `character_info` ON `card_info`.`info_id` = `character_info`.`info_id`
          WHERE `card_type` = $type
          AND `card_type_arg` = $type_arg"
      );
      if ($cardInfo) {
        $cardInfo = $cardInfo[0];
        $ids = ['consequence_black', 'consequence_white', 'consequence_grey'];
        foreach ($ids as $id) {
          if ($cardInfo[$id]) {
            $cardInfo[$id] = json_decode($cardInfo[$id], true);
          }
        }
      } else {
        $cardInfo = array();
      }
    }
    return $cardInfo;
  }

  public function pickCard(string $location, int $player_id): ?array
  {
    $card = $this->game->getCardManager()->pickCard($location, $player_id);
    if ($card) {
      $card_info = $this->getExtendedCardInfo($card['type'], $card['type_arg']);
      $finalCard = array_merge($card, $card_info);
      return $finalCard;
    }
    return null;
  }
  public function getCard(int $card_id): array
  {
    $card = $this->game->getCardManager()->getCard($card_id);
    if ($card === null) {
      throw new \InvalidArgumentException("Card with ID $card_id does not exist.");
    }
    $card_info = $this->getExtendedCardInfo($card['type'], $card['type_arg']);
    return array_merge($card, $card_info);
  }
  function getCardsInLocation(string $location, ?int $location_arg = null, ?string $order_by = null): array
  {
    $cards = $this->game->getCardManager()->getCardsInLocation($location, $location_arg, $order_by);
    return array_map(function ($card) {
      $card_info = $this->getExtendedCardInfo($card['type'], $card['type_arg']);
      return array_merge($card, $card_info);
    }, $cards);
  }
  public function getCardOnTop(string $location): ?array
  {
    $card = $this->game->getCardManager()->getCardOnTop($location);
    if ($card === null) {
      return null;
    }
    $card_info = $this->getExtendedCardInfo($card['type'], $card['type_arg']);
    return array_merge($card, $card_info);
  }
  public function countCardInLocation(string $location, ?int $location_arg = null): int
  {
    return $this->game->getCardManager()->countCardInLocation($location, $location_arg);
  }
  public function insertCardOnExtremePosition(int $card_id, string $location, bool $bOnTop): void
  {
    $this->game->getCardManager()->insertCardOnExtremePosition($card_id, $location, $bOnTop);
  }
  public function moveCard(int $card_id, string $location, int $location_arg = 0): void
  {
    $this->game->getCardManager()->moveCard($card_id, $location, $location_arg);
  }
  public function moveAllCardsInLocation(?string $from_location, ?string $to_location, ?int $from_location_arg = null, int $to_location_arg = 0): void
  {
    $this->game->getCardManager()->moveAllCardsInLocation($from_location, $to_location, $from_location_arg, $to_location_arg);
  }
  public function generateFakeCard($card): array
  {
    $faketype = '';
    switch ($card['type']) {
      case 2:
        $faketype = '4';
        break;
      case 3:
        $faketype = '5';
        break;
      default:
        $faketype = '6';
    }
    return [
      'id' => 'fake-top-card',
      'type' => $faketype,
      'type_arg' => '20',
      'location' => $card['location'],
      'location_arg' => $card['location_arg'],
    ];
  }
  public function createCards(): void
  {
    $cardInfo = $this->game->getObjectListFromDB(
      "SELECT `card_type`, `card_type_arg`
          FROM `card_info`"
    );

    //create protoganist cards
    $prota = [];
    $urban = [];
    $rural = [];
    foreach ($cardInfo as $card) {
      switch ($card['card_type']) {
        case 1: // protagonist
          $prota[] = ['type' => 1, 'type_arg' => $card['card_type_arg'], 'nbr' => 1];
          break;
        case 2: // rural
          $rural[] = ['type' => 2, 'type_arg' => $card['card_type_arg'], 'nbr' => 1];
          break;
        case 3: // urban
          $urban[] = ['type' => 3, 'type_arg' => $card['card_type_arg'], 'nbr' => 1];
          break;
      }
    }
    $this->game->getCardManager()->createCards($prota, 'hand');
    $this->game->getCardManager()->createCards($rural, 'deck_rural');
    $this->game->getCardManager()->shuffle('deck_rural');
    $this->game->getCardManager()->createCards($urban, 'deck_urban');
    $this->game->getCardManager()->shuffle('deck_urban');
  }
}
