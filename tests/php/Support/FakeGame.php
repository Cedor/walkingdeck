<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Support;

final class FakeGame
{
    public FakeCardDeck $cardDeck;
    public FakeCardDeck $disasterDeck;
    public array $cardInfo = [];
    public array $disasterInfo = [];
    public array $cardDefinitions = [];
    public array $state = [];
    public object $notify;

    public function __construct()
    {
        $this->cardDeck = new FakeCardDeck();
        $this->disasterDeck = new FakeCardDeck();
        $this->notify = new class {
            public array $events = [];

            public function all(string $type, $message, array $arguments = []): void
            {
                $this->events[] = compact('type', 'message', 'arguments');
            }
        };
    }

    public function getCardManager(): FakeCardDeck
    {
        return $this->cardDeck;
    }

    public function getDisaster(): FakeCardDeck
    {
        return $this->disasterDeck;
    }

    public function getObjectFromDB(string $query): array
    {
        if (strpos($query, 'twd_disaster_info') !== false) {
            preg_match('/card_type` = (\\d+)/', $query, $matches);
            return $this->disasterInfo[(int) ($matches[1] ?? 0)] ?? [];
        }

        preg_match('/card_type` = (\\d+)/', $query, $typeMatches);
        preg_match('/card_type_arg` = (\\d+)/', $query, $argumentMatches);
        return $this->cardInfo[(int) ($typeMatches[1] ?? 0)][(int) ($argumentMatches[1] ?? 0)] ?? [];
    }

    public function getObjectListFromDB(string $query): array
    {
        return $this->cardDefinitions;
    }

    public function setGameStateInitialValue(string $name, int $value): void
    {
        $this->state[$name] = $value;
    }

    public function setGameStateValue(string $name, int $value): void
    {
        $this->state[$name] = $value;
    }

    public function getGameStateValue(string $name): int
    {
        return $this->state[$name] ?? 0;
    }
}
