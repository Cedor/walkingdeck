<?php

namespace Bga\Games\TheWalkingDeck;

class TWDRessources
{
  private const AVAILABLE = 0;
  private const CONSUMED = 1;
  private const REMOVED = 2;
  private const IDS = [
    'ressource_hunger',
    'ressource_break',
    'ressource_stress',
  ];

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
    // Removed resources no longer exist and must not be sent to clients.
    return array_filter([
      1 => ['id' => 'ressource_hunger', 'consumed' => $this->game->getGameStateValue('ressource_hunger')],
      2 => ['id' => 'ressource_break', 'consumed' => $this->game->getGameStateValue('ressource_break')],
      3 => ['id' => 'ressource_stress', 'consumed' => $this->game->getGameStateValue('ressource_stress')],
    ], static function (array $resource): bool {
      return intval($resource['consumed']) !== self::REMOVED;
    });
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
    if (
      in_array($ressource_id, self::IDS, true)
      && in_array($value, [self::AVAILABLE, self::CONSUMED], true)
      && $this->getRessourceState($ressource_id) !== self::REMOVED
    )
      $this->game->setGameStateValue($ressource_id, $value);
    else
      throw new \InvalidArgumentException(\clienttranslate('Invalid ressource id or state'));
  }

  public function removeRessource(string $ressource_id): void
  {
    if (
      !in_array($ressource_id, self::IDS, true)
      || $this->getRessourceState($ressource_id) === self::REMOVED
    ) {
      throw new \InvalidArgumentException(
        \clienttranslate('Invalid or already removed ressource')
      );
    }

    $this->game->setGameStateValue($ressource_id, self::REMOVED);
    $this->game->notify->all(
      'ressourceRemoved',
      \clienttranslate('Ressource ${resource_id} removed from the game'),
      [
        'id' => $ressource_id,
        'resource_id' => $ressource_id,
      ]
    );
  }

  public function consumeRessources(string $ressource_id): void
  {
    $ressource_consumed = $this->getRessourceState($ressource_id);
    if ($ressource_consumed === self::AVAILABLE) {
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
    if ($this->getRessourceState($ressource_id) === self::CONSUMED) {
      $this->setRessourceState($ressource_id, 0);
      $this->game->notify->all('ressourceRefilled', \clienttranslate('Ressource ${resource_id} refilled'), array(
        'id' => $ressource_id,
        'resource_id' => $ressource_id,
        'consumed' => 0
      ));
    }
  }
}
