<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Support;

final class FakeCardDeck
{
    public array $cards = [];
    public array $createdBatches = [];
    public array $moves = [];
    public array $shuffledLocations = [];

    public function pickCard(string $location, int $playerId): ?array
    {
        foreach ($this->cards as $id => $card) {
            if ($card['location'] === $location) {
                $this->cards[$id]['location'] = 'hand';
                $this->cards[$id]['location_arg'] = $playerId;
                return $this->cards[$id];
            }
        }
        return null;
    }

    public function getCard(int $cardId): ?array
    {
        return $this->cards[$cardId] ?? null;
    }

    public function getCardsInLocation(string $location, ?int $locationArg = null, ?string $orderBy = null): array
    {
        return array_values(array_filter($this->cards, static function (array $card) use ($location, $locationArg): bool {
            return $card['location'] === $location
                && ($locationArg === null || $card['location_arg'] === $locationArg);
        }));
    }

    public function getCardOnTop(string $location): ?array
    {
        $cards = $this->getCardsInLocation($location);
        return $cards ? end($cards) : null;
    }

    public function getCardsOnTop(int $number, string $location): array
    {
        $cards = $this->getCardsInLocation($location);
        usort($cards, static function (array $left, array $right): int {
            return $right['location_arg'] <=> $left['location_arg'];
        });
        return array_slice($cards, 0, $number);
    }

    public function countCardInLocation(string $location, ?int $locationArg = null): int
    {
        return count($this->getCardsInLocation($location, $locationArg));
    }

    public function insertCardOnExtremePosition(int $cardId, string $location, bool $onTop): void
    {
        $this->moveCard($cardId, $location);
    }

    public function moveCard(int $cardId, string $location, int $locationArg = 0): void
    {
        $this->cards[$cardId]['location'] = $location;
        $this->cards[$cardId]['location_arg'] = $locationArg;
        $this->moves[] = [$cardId, $location, $locationArg];
    }

    public function setCardWounds(int $cardId, int $wounds): array
    {
        $this->cards[$cardId]['wounds'] = $wounds;
        return $this->cards[$cardId];
    }

    public function moveAllCardsInLocation(
        ?string $fromLocation,
        ?string $toLocation,
        ?int $fromLocationArg = null,
        int $toLocationArg = 0
    ): void {
        foreach ($this->cards as $id => $card) {
            if ($card['location'] === $fromLocation
                && ($fromLocationArg === null || $card['location_arg'] === $fromLocationArg)) {
                $this->moveCard($id, (string) $toLocation, $toLocationArg);
            }
        }
    }

    public function createCards(array $cards, string $location): void
    {
        $this->createdBatches[] = ['cards' => $cards, 'location' => $location];
    }

    public function shuffle(string $location): void
    {
        $this->shuffledLocations[] = $location;
    }
}
