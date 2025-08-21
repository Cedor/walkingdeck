<?php

namespace Bga\Games\TheWalkingDeck;


class TWDRessources
{
  protected $game;

  public function __construct($game)
  {
    $this->game = $game;
  }

  public function initRessources(): void
  {
    // Initialize ressources to available for all players
    $this->game->setGameStateInitialValue("ressource1", 0);
    $this->game->setGameStateInitialValue("ressource2", 0);
    $this->game->setGameStateInitialValue("ressource3", 0);
  }

  public function getRessources(): array
  {
    // Get all ressources values
    return [
      1 => ["id" => '1', "consumed" => $this->game->getGameStateValue("ressource1")],
      2 => ["id" => '2', "consumed" => $this->game->getGameStateValue("ressource2")],
      3 => ["id" => '3', "consumed" => $this->game->getGameStateValue("ressource3")],
    ];
  }

  public function getRessource(int $ressource_id): int
  {
    // Get the value of a specific ressource
    return $this->game->getGameStateValue("ressource" . $ressource_id);
  }

  public function consumeRessources(int $ressource_id): void
  {
    $this->game->setGameStateValue("ressource" . $ressource_id, 1);
  }

  public function refillRessources(int $ressource_id): void
  {
    $this->game->setGameStateValue("ressource" . $ressource_id, 0);
  }
}
