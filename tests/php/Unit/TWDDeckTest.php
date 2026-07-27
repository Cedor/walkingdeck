<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\Games\TheWalkingDeck\Constants\Location;
use Bga\Games\TheWalkingDeck\Tests\Support\FakeGame;
use Bga\Games\TheWalkingDeck\TWDDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TWDDeckTest extends TestCase
{
    public function testCardDataIsEnrichedAndConsequencesAreDecoded(): void
    {
        $game = new FakeGame();
        $game->cardDeck->cards[7] = [
            'id' => 7,
            'type' => 2,
            'type_arg' => 3,
            'location' => Location::RURAL,
            'location_arg' => 0,
        ];
        $game->cardInfo[2][3] = [
            'card_name' => 'Clown',
            'is_zombie' => 1,
            'is_character' => 0,
            'consequence_black' => null,
            'consequence_white' => null,
            'consequence_grey' => '{"action":"bury","bury":"this"}',
            'special_draw' => 0,
            'weakness_1' => null,
            'weakness_2' => null,
            'weakness_3' => null,
            'wounds' => null,
        ];

        $card = (new TWDDeck($game))->getCard(7);

        self::assertSame('Clown', $card['card_name']);
        self::assertSame(['action' => 'bury', 'bury' => 'this'], $card['consequence_grey']);
    }

    public function testMissingCardIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TWDDeck(new FakeGame()))->getCard(999);
    }

    /**
     * @dataProvider fakeCardTypeProvider
     */
    public function testFakeCardUsesExpectedBack(int $realType, string $fakeType): void
    {
        $card = [
            'id' => 1,
            'type' => $realType,
            'location' => Location::RURAL,
            'location_arg' => 0,
        ];

        $fake = (new TWDDeck(new FakeGame()))->generateFakeCard($card);

        self::assertSame($fakeType, $fake['type']);
        self::assertSame('fake-top-card', $fake['id']);
        self::assertSame($card['location'], $fake['location']);
    }

    public function fakeCardTypeProvider(): array
    {
        return [
            'protagonist' => [1, '4'],
            'urban' => [3, '5'],
            'rural/default' => [2, '6'],
        ];
    }

    public function testCreateCardsBuildsAndShufflesEachDeck(): void
    {
        $game = new FakeGame();
        $game->cardDefinitions = [
            ['card_type' => 1, 'card_type_arg' => 1],
            ['card_type' => 2, 'card_type_arg' => 4],
            ['card_type' => 3, 'card_type_arg' => 9],
        ];

        (new TWDDeck($game))->createCards();

        self::assertSame(
            [Location::HAND, Location::RURAL, Location::URBAN],
            array_column($game->cardDeck->createdBatches, 'location')
        );
        self::assertSame([Location::RURAL, Location::URBAN], $game->cardDeck->shuffledLocations);
        self::assertSame(1, $game->cardDeck->createdBatches[1]['cards'][0]['nbr']);
    }
}
