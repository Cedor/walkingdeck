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
            'consequence_white' => '{"action":"draw","number":2}',
            'consequence_grey' => '{"action":"bury","bury":"this"}',
            'special_draw' => 0,
            'texts' => '{"white":{"text":"Draw ${disaster}","args":{"disaster":{"type":"icon","name":"disaster"}}},"grey":{"text":"Bury this peripeteia"}}',
            'weakness_hunger' => null,
            'weakness_break' => null,
            'weakness_stress' => null,
            'wounds' => null,
        ];

        $card = (new TWDDeck($game))->getCard(7);

        self::assertSame('Clown', $card['card_name']);
        self::assertSame(['action' => 'bury', 'bury' => 'this'], $card['consequence_grey']);
        self::assertSame(2, $card['consequence_white']['number']);
        self::assertSame('Draw ${disaster}', $card['texts']['white']['text']);
        self::assertSame('disaster', $card['texts']['white']['args']['disaster']['name']);
    }

    public function testMissingCardIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TWDDeck(new FakeGame()))->getCard(999);
    }

    public function testCharacterWoundsArePersisted(): void
    {
        $game = new FakeGame();
        $game->cardDeck->cards[8] = [
            'id' => 8,
            'type' => 2,
            'type_arg' => 8,
            'location' => Location::CHARACTERS_IN_PLAY,
            'location_arg' => 0,
        ];
        $game->cardInfo[2][8] = [
            'card_name' => 'Ellie and Joel',
            'is_character' => 1,
            'consequence_black' => null,
            'consequence_white' => null,
            'consequence_grey' => null,
            'wounds' => 0,
        ];

        $card = (new TWDDeck($game))->setCardWounds(8, 2);

        self::assertSame(2, $card['wounds']);
        self::assertStringContainsString(
            'SET `twd_character_info`.`wounds` = 2',
            $game->queries[0]
        );
    }

    public function testCharacterAtFourWoundsIsReturnedFaceDown(): void
    {
        $game = new FakeGame();
        $game->cardDeck->cards[8] = [
            'id' => 8,
            'type' => 2,
            'type_arg' => 8,
            'location' => Location::CHARACTERS_IN_PLAY,
            'location_arg' => 0,
        ];
        $game->cardInfo[2][8] = [
            'card_name' => 'Ellie and Joel',
            'is_character' => 1,
            'consequence_black' => null,
            'consequence_white' => null,
            'consequence_grey' => null,
            'wounds' => 4,
        ];

        $card = (new TWDDeck($game))->getCard(8);

        self::assertTrue($card['face_down']);
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
        self::assertSame(
            Location::RURAL . '-' . $fakeType . '-top-card',
            $fake['id']
        );
        self::assertSame($card['location'], $fake['location']);
    }

    public function fakeCardTypeProvider(): array
    {
        return [
            'protagonist/default' => [1, '6'],
            'urban' => [3, '5'],
            'rural' => [2, '4'],
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
