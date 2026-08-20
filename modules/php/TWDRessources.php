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
    $this->game->setGameStateInitialValue('ressource_hunger', 0);
    $this->game->setGameStateInitialValue('ressource_break', 0);
    $this->game->setGameStateInitialValue('ressource_stress', 0);
  }

  public function getRessources(): array
  {
    // Get all ressources values
    return [
      1 => ['id' => 'ressource_hunger', 'consumed' => $this->game->getGameStateValue('ressource_hunger')],
      2 => ['id' => 'ressource_break', 'consumed' => $this->game->getGameStateValue('ressource_break')],
      3 => ['id' => 'ressource_stress', 'consumed' => $this->game->getGameStateValue('ressource_stress')],
    ];
  }

  public function getRessource(string $ressource_id): array
  {
    // Get the value of a specific ressource
    return ['id' => $ressource_id, 'consumed' => $this->getRessourceState($ressource_id)];
  }

  public function getRessourceState(string $ressource_id): int
  {
    // Get the value of a specific ressource
    return $this->game->getGameStateValue($ressource_id);
  }

  public function setRessourceState(string $ressource_id, int $value): void
  {
    // Set the value of a specific ressource
    if ($ressource_id && ($value == 0 || $value == 1))
      $this->game->setGameStateValue($ressource_id, $value);
    else
      throw new \InvalidArgumentException(\clienttranslate('Invalid ressource id or state'));
  }

  public function consumeRessources(string $ressource_id): void
  {
    $ressource_consumed = $this->getRessourceState($ressource_id);
    if (!$ressource_consumed) {
      $this->setRessourceState($ressource_id, 1);
      $this->game->notify->all('ressourceConsumed', \clienttranslate('Ressource ${resource_id} consumed'), array(
        'id' => $ressource_id,
        'resource_id' => $ressource_id,
        'consumed' => 1
      ));
    }
  }

  public function refillRessources(string $ressource_id): void
  {
    if ($this->getRessourceState($ressource_id)) {
      $this->setRessourceState($ressource_id, 0);
      $this->game->notify->all('ressourceRefilled', \clienttranslate('Ressource ${resource_id} refilled'), array(
        'id' => $ressource_id,
        'resource_id' => $ressource_id,
        'consumed' => 0
      ));
    }
  }
}
