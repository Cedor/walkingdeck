<?php

namespace Bga\Games\TheWalkingDeck;

use Bga\Games\TheWalkingDeck\Constants\CardType;
use Bga\Games\TheWalkingDeck\Constants\Location;

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
      $cardInfo = $this->game->getObjectFromDB(
        "SELECT `card_name`, `translate_name`, `losscon`, `texts`
          FROM `twd_card_info` JOIN `twd_protagonist_info` ON `twd_card_info`.`info_id` = `twd_protagonist_info`.`info_id`
          WHERE `twd_card_info`.`info_id` = `twd_protagonist_info`.`info_id`
          AND `twd_card_info`.`card_type` = $type
          AND `twd_card_info`.`card_type_arg` = $type_arg"
      );
      if (!empty($cardInfo['texts']) && is_string($cardInfo['texts'])) {
        $cardInfo['texts'] = json_decode($cardInfo['texts'], true);
      }
    } else { // type == 2 or 3
      // rural and urban
      $cardInfo = $this->game->getObjectFromDB(
        "SELECT `card_name`, `translate_name`, `is_zombie`, `is_character`, `consequence_black`, `consequence_white`, `consequence_grey`, `special_draw`, `texts`, `weakness_hunger`, `weakness_break`, `weakness_stress`, `wounds`
          FROM `twd_card_info` LEFT JOIN `twd_character_info` ON `twd_card_info`.`info_id` = `twd_character_info`.`info_id`
          WHERE `card_type` = $type
          AND `card_type_arg` = $type_arg"
      );
      if ($cardInfo) {
        $ids = ['consequence_black', 'consequence_white', 'consequence_grey', 'texts'];
        foreach ($ids as $id) {
          if (!empty($cardInfo[$id]) && is_string($cardInfo[$id])) {
            $cardInfo[$id] = json_decode($cardInfo[$id], true);
          }
        }
        if (
          intval($cardInfo['is_character'] ?? 0) === 1
          && intval($cardInfo['wounds'] ?? 0) >= 4
        ) {
          $cardInfo['face_down'] = true;
        }
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
  public function getCardsOnTop(int $number, string $location): array
  {
    $cards = $this->game->getCardManager()->getCardsOnTop($number, $location);
    return array_values(array_map(function ($card) {
      $card_info = $this->getExtendedCardInfo($card['type'], $card['type_arg']);
      return array_merge($card, $card_info);
    }, $cards ?? []));
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
  public function shuffle(string $location): void
  {
    $this->game->getCardManager()->shuffle($location);
  }
  public function pickCardsForLocation(int $number, string $from_location, string $to_location): array
  {
    return $this->game->getCardManager()->pickCardsForLocation(
      $number,
      $from_location,
      $to_location
    ) ?? [];
  }
  public function setCardWounds(int $card_id, int $wounds): array
  {
    $card = $this->getCard($card_id);
    if (intval($card['is_character'] ?? 0) !== 1 || $wounds < 0 || $wounds > 4) {
      throw new \InvalidArgumentException(\clienttranslate('Invalid character wounds'));
    }

    $type = intval($card['type']);
    $type_arg = intval($card['type_arg']);
    $this->game->DbQuery(
      "UPDATE `twd_character_info`
        INNER JOIN `twd_card_info` ON `twd_card_info`.`info_id` = `twd_character_info`.`info_id`
        SET `twd_character_info`.`wounds` = $wounds
        WHERE `twd_card_info`.`card_type` = $type
        AND `twd_card_info`.`card_type_arg` = $type_arg"
    );

    $card['wounds'] = $wounds;
    return $card;
  }
  public function generateFakeCard($card): array
  {
    $faketype = '';
    switch ($card['type']) {
      case CardType::RURAL:
        $faketype = '4';
        break;
      case CardType::URBAN:
        $faketype = '5';
        break;
      default:
        $faketype = '6';
    }
    return [
      'id' => sprintf(
        '%s-%s-top-card',
        $card['location'],
        $faketype
      ),
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
          FROM `twd_card_info`"
    );

    //create protoganist cards
    $prota = [];
    $urban = [];
    $rural = [];
    foreach ($cardInfo as $card) {
      switch ($card['card_type']) {
        case CardType::PROTAGONIST: // protagonist
          $prota[] = ['type' => $card['card_type'], 'type_arg' => $card['card_type_arg'], 'nbr' => 1];
          break;
        case CardType::RURAL: // rural
          $rural[] = ['type' => $card['card_type'], 'type_arg' => $card['card_type_arg'], 'nbr' => 1];
          break;
        case CardType::URBAN: // urban
          $urban[] = ['type' => $card['card_type'], 'type_arg' => $card['card_type_arg'], 'nbr' => 1];
          break;
      }
    }
    $this->game->getCardManager()->createCards($prota, Location::HAND);
    $this->game->getCardManager()->createCards($rural, Location::RURAL);
    $this->game->getCardManager()->shuffle(Location::RURAL);
    $this->game->getCardManager()->createCards($urban, Location::URBAN);
    $this->game->getCardManager()->shuffle(Location::URBAN);
  }
}
