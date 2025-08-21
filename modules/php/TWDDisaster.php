<?php

namespace Bga\Games\TheWalkingDeck;

class TWDDisaster
{
  private $game;

  public function __construct($game)
  {
    $this->game = $game;
  }

  public function initDisaster(): void
  {

    $disasters = [];
    for ($i = 1; $i <= 6; $i++) {
      $disasters[] = [
        'type' => $i,
        'type_arg' => 0,
        'nbr' => 2
      ];
    }
    $this->game->getDisaster()->createCards($disasters, "deck");
    $this->game->getDisaster()->shuffle('deck');
    $blanks = ['type' => 7, 'type_arg' => 0, 'nbr' => 2];
    $this->game->getDisaster()->createCards([$blanks], "slot");
  }
  public function getExtendedCardInfo(int $type): array
  {
    $disaster_info = $this->game->getObjectListFromDB(
      "SELECT `disaster1`, `disaster2`, `disaster3`
        FROM `disaster_info`
        WHERE `card_type` = $type"
    );
    return $disaster_info;
  }

  public function pickCard(string $location, int $player_id): ?array
  {
    $disaster = $this->game->getDisaster()->pickCard($location, $player_id);
    if ($disaster){
      $disaster_info = $this->getExtendedCardInfo($disaster['type']);
      return array_merge($disaster, $disaster_info[0] ?? []);
    }
    return null;
  }
  public function moveCard(int $card_id, string $location, int $location_arg = 0): void
  {
    $this->game->getDisaster()->moveCard($card_id, $location, $location_arg);
  }
    public function moveAllCardsInLocation(?string $from_location, ?string $to_location, ?int $from_location_arg = null, int $to_location_arg = 0): void
  {
    $this->game->getDisaster()->moveAllCardsInLocation($from_location, $to_location, $from_location_arg, $to_location_arg);
  }
}
