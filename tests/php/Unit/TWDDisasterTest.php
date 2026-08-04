<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\Games\TheWalkingDeck\Tests\Support\FakeGame;
use Bga\Games\TheWalkingDeck\TWDDisaster;
use PHPUnit\Framework\TestCase;

final class TWDDisasterTest extends TestCase
{
    public function testInitializationCreatesTwelveDisastersAndTwoBlanks(): void
    {
        $game = new FakeGame();

        (new TWDDisaster($game))->initDisaster();

        self::assertCount(2, $game->disasterDeck->createdBatches);
        self::assertSame('deck', $game->disasterDeck->createdBatches[0]['location']);
        self::assertCount(6, $game->disasterDeck->createdBatches[0]['cards']);
        self::assertSame(12, array_sum(array_column($game->disasterDeck->createdBatches[0]['cards'], 'nbr')));
        self::assertSame([
            'cards' => [['type' => 7, 'type_arg' => 0, 'nbr' => 2]],
            'location' => 'reserve',
        ], $game->disasterDeck->createdBatches[1]);
        self::assertSame(['deck'], $game->disasterDeck->shuffledLocations);
    }

    public function testPickedDisasterIsEnriched(): void
    {
        $game = new FakeGame();
        $game->disasterDeck->cards[3] = [
            'id' => 3,
            'type' => 4,
            'type_arg' => 0,
            'location' => 'deck',
            'location_arg' => 0,
        ];
        $game->disasterInfo[4] = [
            'disaster_hunger' => 1,
            'disaster_break' => 1,
            'disaster_stress' => 0,
        ];

        $disaster = (new TWDDisaster($game))->pickCard('deck', 42);

        self::assertSame(1, $disaster['disaster_hunger']);
        self::assertSame(1, $disaster['disaster_break']);
        self::assertSame(0, $disaster['disaster_stress']);
        self::assertSame(42, $disaster['location_arg']);
    }

    public function testEmptyDeckReturnsNull(): void
    {
        self::assertNull((new TWDDisaster(new FakeGame()))->pickCard('deck', 1));
    }
}
