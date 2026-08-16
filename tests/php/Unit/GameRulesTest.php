<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\GameFramework\UserException;
use Bga\GameFramework\SystemException;
use Bga\Games\TheWalkingDeck\Constants\EventType;
use Bga\Games\TheWalkingDeck\Constants\Location;
use Bga\Games\TheWalkingDeck\Constants\Transition;
use Bga\Games\TheWalkingDeck\Game;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class GameRulesTest extends TestCase
{
    private Game $game;

    protected function setUp(): void
    {
        $this->game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
        $this->game->notify = new class {
            public array $events = [];

            public function all(string $type, $message, array $arguments = []): void
            {
                $this->events[] = compact('type', 'message', 'arguments');
            }
        };
        $this->setProperty(
            'ressources',
            new \Bga\Games\TheWalkingDeck\TWDRessources($this->game)
        );
    }

    public function testNormalDrawRequiresCardsAndRoomInHand(): void
    {
        $counts = [
            Location::RURAL => 1,
            Location::URBAN => 0,
            Location::HAND => 2,
        ];
        $this->setProperty('deckManager', new class($counts) {
            private array $counts;

            public function __construct(array $counts)
            {
                $this->counts = $counts;
            }

            public function countCardInLocation(string $location): int
            {
                return $this->counts[$location] ?? 0;
            }
        });

        self::assertSame(1, $this->invoke('availableDraws'));
        self::assertTrue($this->invoke('canContinueNormalDraw'));
    }

    public function testFullHandStopsNormalDraw(): void
    {
        $this->setProperty('deckManager', new class {
            public function countCardInLocation(string $location): int
            {
                return $location === Location::HAND ? 3 : 10;
            }
        });

        self::assertFalse($this->invoke('canContinueNormalDraw'));
    }

    public function testProtagonistSelectionStillStartsPhaseOne(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[2] = [
            'id' => 2,
            'type' => '1',
            'type_arg' => '2',
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Boris',
            'losscon' => '5',
        ];
        $deckManager->cards[3] = [
            'id' => 3,
            'type' => '1',
            'type_arg' => '3',
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Adrien',
            'losscon' => '4',
        ];
        foreach ([10, 11, 12] as $cardId) {
            $deckManager->cards[$cardId] = [
                'id' => $cardId,
                'type' => '2',
                'type_arg' => (string) $cardId,
                'location' => Location::RURAL,
                'location_arg' => $cardId,
            ];
        }
        foreach ([20, 21] as $cardId) {
            $deckManager->cards[$cardId] = [
                'id' => $cardId,
                'type' => '3',
                'type_arg' => (string) $cardId,
                'location' => Location::URBAN,
                'location_arg' => $cardId,
            ];
        }
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        self::assertNull($this->invoke('getProtagonistInPlay'));

        $this->game->actPlayProtagonistCard(2);

        self::assertSame(
            $deckManager->cards[2],
            $this->invoke('getProtagonistInPlay')
        );
        self::assertSame(Location::PROTAGONIST, $deckManager->cards[2]['location']);
        self::assertSame(Location::DISCARD, $deckManager->cards[3]['location']);
        self::assertSame(2, $this->game->getGameStateValue('difficultyLevel'));
        self::assertSame(5, $this->game->getGameStateValue('lossCondition'));
        $ruralDeckCount = $deckManager->countCardInLocation(Location::RURAL);
        $urbanDeckCount = $deckManager->countCardInLocation(Location::URBAN);
        self::assertSame(5, $ruralDeckCount + $urbanDeckCount);
        self::assertGreaterThanOrEqual(1, $urbanDeckCount);
        self::assertLessThanOrEqual(3, $urbanDeckCount);
        self::assertSame([Location::RURAL], $deckManager->shuffledLocations);
        self::assertSame([Transition::DEFAULT], $gamestate->transitions);
        self::assertSame(
            [
                'protagonistCardPlayed',
                'borisDecksMerged',
                'borisDeckShuffled',
                'borisDecksRedistributed',
            ],
            array_column($this->game->notify->events, 'type')
        );
        self::assertSame(
            $ruralDeckCount,
            end($this->game->notify->events)['arguments']['ruralDeckNb']
        );
        self::assertSame(
            $urbanDeckCount,
            end($this->game->notify->events)['arguments']['urbanDeckNb']
        );
    }

    public function testBorisCanConsumeAResourceToRevealBothDecks(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            2 => [
                'id' => 2,
                'type' => '1',
                'type_arg' => '2',
                'location' => Location::PROTAGONIST,
                'location_arg' => 0,
                'card_name' => 'Boris',
            ],
            10 => [
                'id' => 10,
                'type' => '2',
                'type_arg' => '1',
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Rural card',
            ],
            20 => [
                'id' => 20,
                'type' => '3',
                'type_arg' => '1',
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban card',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->actUseBorisResource('ressource_hunger');

        self::assertSame(1, $this->game->getGameStateValue('ressource_hunger'));
        self::assertSame(10, $this->game->getGameStateValue('revealedRuralCardId'));
        self::assertSame(20, $this->game->getGameStateValue('revealedUrbanCardId'));
        self::assertSame(
            ['ressourceConsumed', 'deckTopRevealed', 'deckTopRevealed'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testNonBorisProtagonistCannotUseBorisResource(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[3] = [
            'id' => 3,
            'type' => '1',
            'type_arg' => '3',
            'location' => Location::PROTAGONIST,
            'location_arg' => 0,
            'card_name' => 'Adrien',
        ];
        $this->setProperty('deckManager', $deckManager);
        $this->game->setGameStateValue('gamePhase', 1);

        $this->expectException(UserException::class);
        $this->game->actUseBorisResource('ressource_hunger');
    }

    public function testBorisCannotReuseAConsumedResource(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[2] = [
            'id' => 2,
            'type' => '1',
            'type_arg' => '2',
            'location' => Location::PROTAGONIST,
            'location_arg' => 0,
            'card_name' => 'Boris',
        ];
        $this->setProperty('deckManager', $deckManager);
        $this->game->setGameStateValue('gamePhase', 1);
        $this->game->setGameStateValue('ressource_break', 1);

        $this->expectException(UserException::class);
        $this->game->actUseBorisResource('ressource_break');
    }

    public function testBorisCannotUseAResourceDuringPhaseTwo(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[2] = [
            'id' => 2,
            'type' => '1',
            'type_arg' => '2',
            'location' => Location::PROTAGONIST,
            'location_arg' => 0,
            'card_name' => 'Boris',
        ];
        $this->setProperty('deckManager', $deckManager);
        $this->game->setGameStateValue('gamePhase', 2);

        $this->expectException(UserException::class);
        $this->game->actUseBorisResource('ressource_hunger');
    }

    /**
     * @dataProvider cardLocationProvider
     */
    public function testCardLocationRules(array $card, string $location, bool $expected): void
    {
        self::assertSame($expected, (bool) $this->invoke('cardCanBePlayedInLocation', [$card, $location]));
    }

    public function cardLocationProvider(): array
    {
        $base = [
            'is_character' => '0',
            'consequence_white' => null,
            'consequence_grey' => null,
            'consequence_black' => null,
        ];

        return [
            'character accepted' => [array_merge($base, ['is_character' => '1']), Location::CHARACTERS_IN_PLAY, true],
            'non-character rejected' => [$base, Location::CHARACTERS_IN_PLAY, false],
            'white consequence in memory' => [array_merge($base, ['consequence_white' => ['action' => 'draw']]), Location::MEMORY, true],
            'no memory consequence' => [$base, Location::MEMORY, false],
            'black consequence escaped' => [array_merge($base, ['consequence_black' => ['action' => 'nothing']]), Location::ESCAPED, true],
            'no black consequence escaped' => [$base, Location::ESCAPED, false],
        ];
    }

    public function testMultipleConsequencesAreAggregated(): void
    {
        $card = [
            'id' => 5,
            'consequence_black' => [
                'action' => 'multiple',
                'number' => 2,
                '0' => ['action' => 'draw', 'number' => 2],
                '1' => ['action' => 'forcePass'],
            ],
        ];

        $outcome = $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame([
            'additionalDraws' => 2,
            'startNormalDraw' => true,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => false,
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ], $outcome);
        self::assertCount(2, $this->game->notify->events);
    }

    public function testEscapeTallaRequestsAPlayerChoice(): void
    {
        $card = [
            'id' => 10,
            'consequence_white' => ['action' => 'escapeTalla'],
        ];

        self::assertSame([
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => true,
            'avoidZombieChoice' => false,
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'white']));
    }

    public function testUnearthMovesTheTopGraveyardCardToEscaped(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            41 => [
                'id' => 41,
                'type' => '2',
                'location' => Location::GRAVEYARD,
                'location_arg' => 1,
                'card_name' => 'First buried card',
            ],
            42 => [
                'id' => 42,
                'type' => '3',
                'location' => Location::GRAVEYARD,
                'location_arg' => 2,
                'card_name' => 'Top buried card',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);
        $card = [
            'id' => 30,
            'consequence_black' => ['action' => 'unearth'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[41]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[42]['location']);
        self::assertSame([42, Location::ESCAPED, 0], $deckManager->moves[0]);
        $notification = end($this->game->notify->events);
        self::assertSame('cardMoved', $notification['type']);
        self::assertSame(1, $notification['arguments']['graveyardNb']);
        self::assertSame(
            [
                'id' => 41,
                'type' => '4',
                'type_arg' => '20',
                'location' => Location::GRAVEYARD,
                'location_arg' => 1,
            ],
            $notification['arguments']['graveyardTop']
        );
    }

    public function testBuriedCardsAreInsertedOnTopOfTheGraveyard(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[40] = [
            'id' => 40,
            'location' => Location::ESCAPED,
            'location_arg' => 0,
            'card_name' => 'Buried card',
            'consequence_black' => [
                'action' => 'bury',
                'bury' => 'this',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);

        $this->invoke(
            'applyConsequences',
            [$deckManager->cards[40], 'black']
        );

        self::assertSame(
            [[40, Location::GRAVEYARD, true]],
            $deckManager->extremeInsertions
        );
    }

    public function testUnearthDoesNothingWhenTheGraveyardIsEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $this->setProperty('deckManager', $deckManager);
        $card = [
            'id' => 30,
            'consequence_black' => ['action' => 'unearth'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame([], $deckManager->moves);
        self::assertCount(1, $this->game->notify->events);
        self::assertSame(
            'applyingConsequence',
            $this->game->notify->events[0]['type']
        );
    }

    public function testRevealTurnsBothDeckTopCardsFaceUp(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            11 => [
                'id' => 11,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Rural second',
            ],
            10 => [
                'id' => 10,
                'location' => Location::RURAL,
                'location_arg' => 2,
                'card_name' => 'Rural top',
            ],
            20 => [
                'id' => 20,
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban top',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);
        $card = [
            'id' => 32,
            'consequence_black' => ['action' => 'reveal'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame(10, $this->game->getGameStateValue('revealedRuralCardId'));
        self::assertSame(20, $this->game->getGameStateValue('revealedUrbanCardId'));
        self::assertSame(10, $this->invoke('getRevealedDeckTop', [Location::RURAL])['id']);
        self::assertSame(20, $this->invoke('getRevealedDeckTop', [Location::URBAN])['id']);
        $revealEvents = array_values(array_filter(
            $this->game->notify->events,
            static fn(array $event): bool => $event['type'] === 'deckTopRevealed'
        ));
        self::assertCount(2, $revealEvents);
        self::assertFalse($revealEvents[0]['arguments']['card']['face_down']);
        self::assertSame(2, $revealEvents[0]['arguments']['cardNumber']);
        self::assertFalse($revealEvents[1]['arguments']['card']['face_down']);
        self::assertSame(1, $revealEvents[1]['arguments']['cardNumber']);
    }

    public function testHiddenDeckTopUsesTheActualTopCardBack(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[20] = [
            'id' => 20,
            'type' => '3',
            'location' => Location::RURAL,
            'location_arg' => 1,
        ];
        $this->setProperty('deckManager', $deckManager);

        $topCard = $this->invoke('getDeckTopForDisplay', [Location::RURAL]);

        self::assertSame('5', $topCard['type']);
        self::assertSame(Location::RURAL, $topCard['location']);
    }

    public function testRevealIgnoresAnEmptyDeckAndExpiresWhenTheCardLeaves(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[20] = [
            'id' => 20,
            'location' => Location::URBAN,
            'location_arg' => 1,
            'card_name' => 'Urban top',
        ];
        $this->setProperty('deckManager', $deckManager);
        $card = [
            'id' => 32,
            'consequence_black' => ['action' => 'reveal'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame(0, $this->game->getGameStateValue('revealedRuralCardId'));
        self::assertSame(20, $this->game->getGameStateValue('revealedUrbanCardId'));

        $this->invoke('clearDeckRevealIfCardLeaves', [20, Location::URBAN]);

        self::assertSame(0, $this->game->getGameStateValue('revealedUrbanCardId'));
    }

    public function testRevealIsIgnoredWhenBothDecksAreEmpty(): void
    {
        $this->setProperty(
            'deckManager',
            new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck()
        );
        $card = [
            'id' => 32,
            'consequence_black' => ['action' => 'reveal'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame(0, $this->game->getGameStateValue('revealedRuralCardId'));
        self::assertSame(0, $this->game->getGameStateValue('revealedUrbanCardId'));
        self::assertSame(
            ['applyingConsequence'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testRemoveDisasterRemovesOnlyTheRequestedSingleSymbol(): void
    {
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards = [
            1 => [
                'id' => 1,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 0,
                'disaster_break' => 1,
                'disaster_stress' => 0,
            ],
            2 => [
                'id' => 2,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 0,
                'disaster_break' => 1,
                'disaster_stress' => 0,
            ],
            3 => [
                'id' => 3,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 1,
                'disaster_break' => 1,
                'disaster_stress' => 0,
            ],
            4 => [
                'id' => 4,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 0,
                'disaster_break' => 0,
                'disaster_stress' => 1,
            ],
        ];
        $this->setProperty('disasterManager', $disasterManager);
        $card = [
            'id' => 20,
            'consequence_black' => [
                'action' => 'removedisaster',
                'disaster' => 'break',
            ],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame('removed', $disasterManager->cards[1]['location']);
        self::assertSame('removed', $disasterManager->cards[2]['location']);
        self::assertSame('deck', $disasterManager->cards[3]['location']);
        self::assertSame('deck', $disasterManager->cards[4]['location']);
        $notification = end($this->game->notify->events);
        self::assertSame('disastersRemoved', $notification['type']);
        self::assertSame(2, $notification['arguments']['count']);
        self::assertSame('break', $notification['arguments']['characteristic']);
    }

    public function testRemoveDisasterRejectsAnUnknownCharacteristic(): void
    {
        $this->setProperty(
            'disasterManager',
            new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck()
        );
        $card = [
            'id' => 20,
            'consequence_black' => [
                'action' => 'removedisaster',
                'disaster' => 'unknown',
            ],
        ];

        $this->expectException(SystemException::class);
        $this->invoke('applyConsequences', [$card, 'black']);
    }

    public function testAddEmptyDisastersMovesTheReserveToTheBagAndShuffles(): void
    {
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards = [
            1 => [
                'id' => 1,
                'location' => 'deck',
                'location_arg' => 0,
                'type' => 1,
            ],
            13 => [
                'id' => 13,
                'location' => 'reserve',
                'location_arg' => 0,
                'type' => 7,
            ],
            14 => [
                'id' => 14,
                'location' => 'reserve',
                'location_arg' => 0,
                'type' => 7,
            ],
        ];
        $this->setProperty('disasterManager', $disasterManager);
        $card = [
            'id' => 21,
            'consequence_black' => ['action' => 'addemptydisasters'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame('deck', $disasterManager->cards[13]['location']);
        self::assertSame('deck', $disasterManager->cards[14]['location']);
        self::assertSame(['deck'], $disasterManager->shuffledLocations);
        $notification = end($this->game->notify->events);
        self::assertSame('emptyDisastersAdded', $notification['type']);
        self::assertSame(2, $notification['arguments']['count']);
        self::assertSame([13, 14], array_map(
            'intval',
            array_column($notification['arguments']['disasters'], 'id')
        ));
    }

    public function testAddEmptyDisastersDoesNothingWhenTheReserveIsEmpty(): void
    {
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards[1] = [
            'id' => 1,
            'location' => 'deck',
            'location_arg' => 0,
            'type' => 1,
        ];
        $this->setProperty('disasterManager', $disasterManager);
        $card = [
            'id' => 21,
            'consequence_black' => ['action' => 'addemptydisasters'],
        ];

        $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame([], $disasterManager->moves);
        self::assertSame([], $disasterManager->shuffledLocations);
        self::assertSame(
            ['applyingConsequence'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAvoidZombieRequestsAHandZombieChoice(): void
    {
        $card = [
            'id' => 6,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'zombie',
            ],
        ];

        self::assertSame([
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => true,
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'black']));
    }

    public function testAvoidBothDeckEscapesBothTopCardsAndBuriesCharacters(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            10 => [
                'id' => 10,
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban lower card',
                'is_character' => '0',
            ],
            11 => [
                'id' => 11,
                'location' => Location::URBAN,
                'location_arg' => 2,
                'card_name' => 'Urban top card',
                'is_character' => '0',
                'consequence_black' => ['action' => 'bite', 'bite' => 3],
            ],
            20 => [
                'id' => 20,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Rural lower card',
                'is_character' => '0',
            ],
            21 => [
                'id' => 21,
                'location' => Location::RURAL,
                'location_arg' => 2,
                'card_name' => 'Rural top character',
                'is_character' => '1',
                'consequence_black' => ['action' => 'disaster', 'number' => 1],
            ],
        ];
        $this->setProperty('deckManager', $deckManager);
        $this->game->setGameStateValue('revealedUrbanCardId', 11);
        $this->game->setGameStateValue('revealedRuralCardId', 21);
        $sourceCard = [
            'id' => 19,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'bothdeck',
            ],
        ];

        $outcome = $this->invoke('applyConsequences', [$sourceCard, 'black']);

        self::assertSame(Location::ESCAPED, $deckManager->cards[11]['location']);
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[21]['location']);
        self::assertSame(Location::URBAN, $deckManager->cards[10]['location']);
        self::assertSame(Location::RURAL, $deckManager->cards[20]['location']);
        self::assertSame(
            [[11, Location::ESCAPED, 0], [21, Location::GRAVEYARD, 0]],
            $deckManager->moves
        );
        self::assertTrue($outcome['checkLoss']);
        self::assertSame(0, $this->game->getGameStateValue('revealedUrbanCardId'));
        self::assertSame(0, $this->game->getGameStateValue('revealedRuralCardId'));
        self::assertSame(
            ['applyingConsequence', 'cardMoved', 'cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAvoidMemoryEscapesTheTopMemoryCard(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            10 => [
                'id' => 10,
                'location' => Location::MEMORY,
                'location_arg' => 1,
                'card_name' => 'Lower memory card',
            ],
            11 => [
                'id' => 11,
                'location' => Location::MEMORY,
                'location_arg' => 2,
                'card_name' => 'Top memory card',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);
        $sourceCard = [
            'id' => 22,
            'consequence_grey' => [
                'action' => 'avoid',
                'avoid' => 'memory',
            ],
        ];

        $this->invoke('applyConsequences', [$sourceCard, 'grey']);

        self::assertSame(Location::MEMORY, $deckManager->cards[10]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[11]['location']);
        self::assertSame(
            [[11, Location::ESCAPED, 0]],
            $deckManager->moves
        );
        self::assertSame(
            ['applyingConsequence', 'cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAvoidMemoryIsIgnoredWhenMemoryIsEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $this->setProperty('deckManager', $deckManager);
        $sourceCard = [
            'id' => 22,
            'consequence_grey' => [
                'action' => 'avoid',
                'avoid' => 'memory',
            ],
        ];

        $this->invoke('applyConsequences', [$sourceCard, 'grey']);

        self::assertSame([], $deckManager->moves);
        self::assertSame(
            ['applyingConsequence'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAvoidBothDeckIsIgnoredWhenBothDecksAreEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $this->setProperty('deckManager', $deckManager);
        $sourceCard = [
            'id' => 19,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'bothdeck',
            ],
        ];

        $outcome = $this->invoke('applyConsequences', [$sourceCard, 'black']);

        self::assertSame([], $deckManager->moves);
        self::assertFalse($outcome['checkLoss']);
        self::assertSame(
            ['applyingConsequence'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAvoidDeckRequestsADeckAndEscapesItsTopTwoCards(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            22 => [
                'id' => 22,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Grenade',
                'consequence_black' => [
                    'action' => 'avoid',
                    'avoid' => 'deck',
                ],
            ],
            10 => [
                'id' => 10,
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban lower card',
                'consequence_black' => ['action' => 'bite', 'bite' => 3],
            ],
            11 => [
                'id' => 11,
                'location' => Location::URBAN,
                'location_arg' => 2,
                'card_name' => 'Urban second card',
                'is_character' => '1',
            ],
            12 => [
                'id' => 12,
                'location' => Location::URBAN,
                'location_arg' => 3,
                'card_name' => 'Urban top card',
            ],
            20 => [
                'id' => 20,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Rural card',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 22,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame([Transition::AVOID_DECK_CHOICE], $gamestate->transitions);
        self::assertSame(
            [Location::RURAL, Location::URBAN],
            $this->game->argAvoidDeckChoice()['availableDecks']
        );

        $this->game->actAvoidDeck(Location::URBAN);

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::URBAN, $deckManager->cards[10]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[11]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[12]['location']);
        self::assertSame(Location::RURAL, $deckManager->cards[20]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[22]['location']);
        self::assertSame(
            [Transition::AVOID_DECK_CHOICE, Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
        self::assertSame(
            ['applyingConsequence', 'cardMoved', 'cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAvoidDeckUsesTheOnlyNonEmptyDeckImmediately(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            10 => [
                'id' => 10,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Rural lower card',
            ],
            11 => [
                'id' => 11,
                'location' => Location::RURAL,
                'location_arg' => 2,
                'card_name' => 'Rural top card',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);
        $sourceCard = [
            'id' => 22,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'deck',
            ],
        ];

        $outcome = $this->invoke('applyConsequences', [$sourceCard, 'black']);

        self::assertSame(Location::ESCAPED, $deckManager->cards[10]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[11]['location']);
        self::assertSame([], $outcome['avoidDeckChoices']);
    }

    public function testAvoidDeckIsIgnoredWhenBothDecksAreEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $this->setProperty('deckManager', $deckManager);
        $sourceCard = [
            'id' => 22,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'deck',
            ],
        ];

        $outcome = $this->invoke('applyConsequences', [$sourceCard, 'black']);

        self::assertSame([], $deckManager->moves);
        self::assertSame([], $outcome['avoidDeckChoices']);
    }

    public function testBrainstormRequestsDeckSelection(): void
    {
        $card = [
            'id' => 25,
            'consequence_white' => ['action' => 'brainstorm'],
        ];

        self::assertSame([
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => false,
            'avoidHandChoice' => false,
            'brainstorm' => true,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'white']));
    }

    public function testUnrememberRequestsAMemoryChoice(): void
    {
        $card = [
            'id' => 9,
            'consequence_black' => ['action' => 'unremember'],
        ];

        $outcome = $this->invoke('applyConsequences', [$card, 'black']);

        self::assertSame(
            [['sourceCardId' => 9]],
            $outcome['unrememberChoices']
        );
    }

    public function testUnrememberRecoversSelectedCardsAndKeepsMemoryOrder(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            9 => [
                'id' => 9,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Kieren',
                'consequence_black' => [
                    'action' => 'multiple',
                    'number' => 2,
                    '0' => ['action' => 'unremember'],
                    '1' => ['action' => 'bury', 'bury' => 'this'],
                ],
            ],
            10 => [
                'id' => 10,
                'location' => Location::MEMORY,
                'location_arg' => 1,
                'card_name' => 'Memory bottom',
            ],
            11 => [
                'id' => 11,
                'location' => Location::MEMORY,
                'location_arg' => 2,
                'card_name' => 'Memory third',
            ],
            12 => [
                'id' => 12,
                'location' => Location::MEMORY,
                'location_arg' => 3,
                'card_name' => 'Memory second',
            ],
            13 => [
                'id' => 13,
                'location' => Location::MEMORY,
                'location_arg' => 4,
                'card_name' => 'Memory top',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 9,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::UNREMEMBER_CHOICE],
            $gamestate->transitions
        );
        self::assertSame(
            [13, 12, 11],
            array_column($this->game->argUnrememberChoice()['cards'], 'id')
        );
        self::assertSame(2, $this->game->argUnrememberChoice()['maximumCards']);

        $this->game->actConfirmUnremember('[13,11]');

        self::assertFalse($eventStack->isEmpty());
        self::assertSame(Location::HAND, $deckManager->cards[13]['location']);
        self::assertSame(Location::HAND, $deckManager->cards[11]['location']);
        self::assertSame(Location::MEMORY, $deckManager->cards[12]['location']);
        self::assertSame(Location::MEMORY, $deckManager->cards[10]['location']);
        self::assertSame(
            [[12, Location::MEMORY, true]],
            $deckManager->extremeInsertions
        );

        $this->game->setGameStateValue('lossCondition', 5);
        $this->game->setGameStateValue('gamePhase', 1);
        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(
            Location::GRAVEYARD,
            $deckManager->cards[9]['location']
        );
        self::assertSame(
            [
                Transition::UNREMEMBER_CHOICE,
                Transition::DISPATCH_EVENTS,
                Transition::PLAY_CARDS,
            ],
            $gamestate->transitions
        );
    }

    public function testUnrememberCanReturnEveryCardInItsOriginalOrder(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            9 => [
                'id' => 9,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Kieren',
            ],
            13 => [
                'id' => 13,
                'location' => Location::UNREMEMBER,
                'location_arg' => 0,
                'card_name' => 'Memory top',
            ],
            12 => [
                'id' => 12,
                'location' => Location::UNREMEMBER,
                'location_arg' => 1,
                'card_name' => 'Memory second',
            ],
            11 => [
                'id' => 11,
                'location' => Location::UNREMEMBER,
                'location_arg' => 2,
                'card_name' => 'Memory third',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::UNREMEMBER_CHOICE, [
            'sourceCardId' => 9,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->actConfirmUnremember('[]');

        self::assertSame(
            [
                [11, Location::MEMORY, true],
                [12, Location::MEMORY, true],
                [13, Location::MEMORY, true],
            ],
            $deckManager->extremeInsertions
        );
    }

    public function testUnrememberIsIgnoredWhenMemoryIsEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[9] = [
            'id' => 9,
            'location' => Location::ESCAPED,
            'location_arg' => 0,
            'card_name' => 'Kieren',
            'consequence_black' => ['action' => 'unremember'],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 9,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame([Transition::STORY_CHECK], $gamestate->transitions);
        self::assertSame([], $deckManager->moves);
    }

    public function testUnrememberRejectsMoreThanTwoCards(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            9 => [
                'id' => 9,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Kieren',
            ],
            11 => [
                'id' => 11,
                'location' => Location::UNREMEMBER,
                'location_arg' => 0,
                'card_name' => 'First card',
            ],
            12 => [
                'id' => 12,
                'location' => Location::UNREMEMBER,
                'location_arg' => 1,
                'card_name' => 'Second card',
            ],
            13 => [
                'id' => 13,
                'location' => Location::UNREMEMBER,
                'location_arg' => 2,
                'card_name' => 'Third card',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::UNREMEMBER_CHOICE, [
            'sourceCardId' => 9,
        ]);
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);

        $this->expectException(UserException::class);

        $this->game->actConfirmUnremember('[11,12,13]');
    }

    public function testRecoverMovesAChosenEscapedCardToHand(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            39 => [
                'id' => 39,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'LGS',
                'consequence_black' => ['action' => 'recover'],
            ],
            12 => [
                'id' => 12,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Robert',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 39,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame([Transition::RECOVER_CHOICE], $gamestate->transitions);
        self::assertSame(
            [39, 12],
            $this->game->argRecoverChoice()['eligibleCardsIds']
        );

        $this->game->actRecoverCard(12);

        self::assertSame(Location::HAND, $deckManager->cards[12]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[39]['location']);
        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Transition::DISPATCH_EVENTS, end($gamestate->transitions));
    }

    public function testRecoverRejectsACardOutsideEscaped(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            39 => [
                'id' => 39,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'LGS',
            ],
            12 => [
                'id' => 12,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Robert',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::RECOVER_CHOICE, [
            'sourceCardId' => 39,
        ]);
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);

        $this->expectException(UserException::class);
        $this->game->actRecoverCard(12);
    }

    public function testDraftDisasterDrawsTwoAndRemovesTheSelectedToken(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[21] = [
            'id' => 21,
            'location' => Location::ESCAPED,
            'location_arg' => 0,
            'card_name' => 'Mutt',
            'consequence_black' => ['action' => 'draftdisaster'],
        ];
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards = [
            1 => [
                'id' => 1,
                'type' => 1,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 1,
                'disaster_break' => 0,
                'disaster_stress' => 0,
            ],
            2 => [
                'id' => 2,
                'type' => 2,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 0,
                'disaster_break' => 1,
                'disaster_stress' => 0,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 21,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::DRAFT_DISASTER_CHOICE],
            $gamestate->transitions
        );
        self::assertSame('draw', $this->game->argDraftDisasterChoice()['phase']);

        $this->game->actDrawDraftDisasters();

        $args = $this->game->argDraftDisasterChoice();
        self::assertSame('choose', $args['phase']);
        self::assertSame([1, 2], $args['eligibleDisasterIds']);
        self::assertCount(2, $args['drawnDisasters']);

        $this->game->actResolveDraftDisaster(1);

        self::assertTrue($eventStack->isEmpty());
        self::assertSame('removed', $disasterManager->cards[1]['location']);
        self::assertSame('deck', $disasterManager->cards[2]['location']);
        self::assertSame(
            [
                Transition::DRAFT_DISASTER_CHOICE,
                Transition::DRAFT_DISASTER_CHOICE,
                Transition::DISPATCH_EVENTS,
            ],
            $gamestate->transitions
        );
        $notification = end($this->game->notify->events);
        self::assertSame('disastersResolved', $notification['type']);
        self::assertSame(
            [1],
            array_column($notification['arguments']['removedDisasters'], 'id')
        );
        self::assertSame(
            [2],
            array_column($notification['arguments']['returnedDisasters'], 'id')
        );
    }

    public function testDraftDisasterRejectsATokenThatWasNotDrawn(): void
    {
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards[1] = [
            'id' => 1,
            'type' => 1,
            'location' => 'hand',
            'location_arg' => 1,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::DRAFT_DISASTER_CHOICE, [
            'sourceCardId' => 21,
        ]);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);

        $this->expectException(UserException::class);
        $this->game->actResolveDraftDisaster(99);
    }

    /**
     * @dataProvider disasterConsequenceProvider
     */
    public function testDisasterConsequencesRequestTheSharedPlayerTreatment(
        array $consequence
    ): void {
        $card = [
            'id' => 8,
            'consequence_grey' => $consequence,
        ];

        $outcome = $this->invoke('applyConsequences', [$card, 'grey']);

        self::assertIsInt(
            $outcome['disasterChoices'][0]['consequence']['number']
        );
        self::assertSame([
            [
                'sourceCardId' => 8,
                'consequence' => $consequence,
            ],
        ], $outcome['disasterChoices']);
    }

    public function disasterConsequenceProvider(): array
    {
        return [
            'standard' => [['action' => 'disaster', 'number' => 1]],
            'ignore' => [[
                'action' => 'disasterignore',
                'number' => 1,
                'ignore' => 'stress',
            ]],
            'aggravate two' => [[
                'action' => 'disasteraggravate2',
                'number' => 1,
                'aggravate1' => 'break',
                'aggravate2' => 'stress',
            ]],
        ];
    }

    public function testDisasterIgnoreRemovesTheNamedCharacteristic(): void
    {
        $disasters = [[
            'disaster_hunger' => 1,
            'disaster_break' => 1,
            'disaster_stress' => 0,
        ]];
        $consequence = [
            'action' => 'disasterignore',
            'number' => 1,
            'ignore' => 'hunger',
        ];

        self::assertFalse($this->invoke(
            'disasterCharacteristicIsPresent',
            ['hunger', $disasters, $consequence]
        ));
        self::assertTrue($this->invoke(
            'disasterCharacteristicIsPresent',
            ['break', $disasters, $consequence]
        ));
    }

    public function testDisasterAggravateForcesBothNamedCharacteristics(): void
    {
        $disasters = [[
            'disaster_hunger' => 1,
            'disaster_break' => 0,
            'disaster_stress' => 0,
        ]];
        $consequence = [
            'action' => 'disasteraggravate2',
            'number' => 1,
            'aggravate1' => 'break',
            'aggravate2' => 'stress',
        ];

        self::assertTrue($this->invoke(
            'disasterCharacteristicIsPresent',
            ['hunger', $disasters, $consequence]
        ));
        self::assertTrue($this->invoke(
            'disasterCharacteristicIsPresent',
            ['break', $disasters, $consequence]
        ));
        self::assertTrue($this->invoke(
            'disasterCharacteristicIsPresent',
            ['stress', $disasters, $consequence]
        ));
    }

    public function testDisasterWaitsForThePlayerThenResumesEvents(): void
    {
        $consequence = ['action' => 'disaster', 'number' => 2];
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            8 => [
                'id' => 8,
                'location' => Location::STORY_CURRENT,
                'location_arg' => 0,
                'card_name' => 'Site Manager',
                'consequence_grey' => $consequence,
            ],
            25 => [
                'id' => 25,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Glenn',
                'is_character' => 1,
                'weakness_hunger' => 1,
                'weakness_break' => 0,
                'weakness_stress' => 1,
                'wounds' => 3,
            ],
            26 => [
                'id' => 26,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Murphy',
                'is_character' => 1,
                'weakness_hunger' => 0,
                'weakness_break' => 1,
                'weakness_stress' => 0,
                'wounds' => 1,
            ],
        ];
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards = [
            1 => [
                'id' => 1,
                'type' => 4,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 1,
                'disaster_break' => 1,
                'disaster_stress' => 0,
            ],
            2 => [
                'id' => 2,
                'type' => 3,
                'location' => 'deck',
                'location_arg' => 0,
                'disaster_hunger' => 0,
                'disaster_break' => 0,
                'disaster_stress' => 1,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 8,
            'color' => 'grey',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::DISASTER_CHOICE],
            $gamestate->transitions
        );
        self::assertSame(
            $consequence,
            $this->game->argDisasterChoice()['consequence']
        );

        self::assertSame('draw', $this->game->argDisasterChoice()['phase']);
        $this->game->actDrawDisaster();
        self::assertSame('confirmDraw', $this->game->argDisasterChoice()['phase']);
        self::assertSame(1, $this->game->argDisasterChoice()['confirmedDraws']);
        self::assertCount(2, $this->game->argDisasterChoice()['drawnDisasters']);
        $this->game->actConfirmDisasterDraw();

        $args = $this->game->argDisasterChoice();
        self::assertSame('characteristic', $args['phase']);
        self::assertSame('hunger', $args['characteristic']);
        self::assertTrue($args['characteristicPresent']);
        self::assertSame([25], array_column($args['affectedCharacters'], 'id'));

        $this->game->actUseDisasterResource('ressource_hunger');
        self::assertTrue(
            $this->game->argDisasterChoice()['characteristicIgnored']
        );
        $this->game->actConfirmDisasterCharacteristic();
        self::assertSame(3, $deckManager->cards[25]['wounds']);
        self::assertSame('break', $this->game->argDisasterChoice()['characteristic']);
        $this->game->actConfirmDisasterCharacteristic();
        self::assertSame(2, $deckManager->cards[26]['wounds']);
        self::assertSame('stress', $this->game->argDisasterChoice()['characteristic']);
        self::assertSame(
            [25],
            array_column(
                $this->game->argDisasterChoice()['affectedCharacters'],
                'id'
            )
        );
        $this->game->actConfirmDisasterCharacteristic();
        self::assertSame(4, $deckManager->cards[25]['wounds']);
        self::assertSame('complete', $this->game->argDisasterChoice()['phase']);

        $this->game->actResolveDisaster();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[25]['location']);
        self::assertSame('deck', $disasterManager->cards[1]['location']);
        self::assertSame('deck', $disasterManager->cards[2]['location']);
        $notification = end($this->game->notify->events);
        self::assertSame('disastersResolved', $notification['type']);
        self::assertSame([], $notification['arguments']['removedDisasters']);
        self::assertSame(
            [1, 2],
            array_column($notification['arguments']['returnedDisasters'], 'id')
        );
        self::assertSame(Transition::DISPATCH_EVENTS, end($gamestate->transitions));
    }

    public function testSingleDisasterDrawImmediatelyRequiresConfirmation(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[8] = [
            'id' => 8,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Ellie and Joel',
        ];
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards[1] = [
            'id' => 1,
            'type' => 1,
            'location' => 'deck',
            'location_arg' => 0,
            'disaster_hunger' => 1,
            'disaster_break' => 0,
            'disaster_stress' => 0,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::DISASTER_CHOICE, [
            'sourceCardId' => 8,
            'consequence' => [
                'action' => 'disaster',
                'number' => 1,
            ],
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        self::assertSame('draw', $this->game->argDisasterChoice()['phase']);

        $this->game->actDrawDisaster();

        $args = $this->game->argDisasterChoice();
        self::assertSame('confirmDraw', $args['phase']);
        self::assertSame(0, $args['confirmedDraws']);
        self::assertCount(1, $args['drawnDisasters']);

        $this->game->actConfirmDisasterDraw();

        $args = $this->game->argDisasterChoice();
        self::assertSame('characteristic', $args['phase']);
        self::assertSame('hunger', $args['characteristic']);
        self::assertSame(1, $args['confirmedDraws']);
        self::assertTrue($args['resourceAvailable']);
        self::assertSame('ressource_hunger', $args['resourceId']);

        $this->game->actUseDisasterResource('ressource_hunger');

        $args = $this->game->argDisasterChoice();
        self::assertTrue($args['characteristicIgnored']);
        self::assertFalse($args['characteristicPresent']);
        self::assertFalse($args['resourceAvailable']);
        self::assertSame(1, $this->game->getGameStateValue('ressource_hunger'));
    }

    public function testWolfTrapBuriesItselfForBreakAndAnotherCharacteristic(): void
    {
        [$deckManager, $disasterManager, $eventStack, $gamestate] =
            $this->prepareWolfTrapDisaster([
                'disaster_hunger' => 1,
                'disaster_break' => 1,
                'disaster_stress' => 0,
            ]);

        $this->game->stEventDispatcher();
        self::assertSame([Transition::WOLF_TRAP_CHOICE], $gamestate->transitions);

        $this->game->actDrawWolfTrapDisaster();

        $args = $this->game->argWolfTrapChoice();
        self::assertSame('resolve', $args['phase']);
        self::assertTrue($args['willBury']);

        $this->game->actResolveWolfTrap();

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[6]['location']);
        $notification = end($this->game->notify->events);
        self::assertSame('disastersResolved', $notification['type']);
        self::assertSame([], $notification['arguments']['removedDisasters']);
        self::assertSame(
            [1],
            array_column($notification['arguments']['returnedDisasters'], 'id')
        );
        self::assertSame('deck', $disasterManager->cards[1]['location']);
        self::assertSame(
            ['deck', 'deck'],
            $disasterManager->shuffledLocations
        );
        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Transition::DISPATCH_EVENTS, end($gamestate->transitions));
    }

    public function testWolfTrapStaysEscapedWithoutBreak(): void
    {
        [$deckManager] = $this->prepareWolfTrapDisaster([
            'disaster_hunger' => 1,
            'disaster_break' => 0,
            'disaster_stress' => 1,
        ]);

        $this->game->actDrawWolfTrapDisaster();

        self::assertFalse($this->game->argWolfTrapChoice()['willBury']);

        $this->game->actResolveWolfTrap();

        self::assertSame(Location::ESCAPED, $deckManager->cards[6]['location']);
    }

    public function testWolfTrapBuriesItselfForBreakAndStress(): void
    {
        [$deckManager] = $this->prepareWolfTrapDisaster([
            'disaster_hunger' => 0,
            'disaster_break' => 1,
            'disaster_stress' => 1,
        ]);

        $this->game->actDrawWolfTrapDisaster();

        self::assertTrue($this->game->argWolfTrapChoice()['willBury']);

        $this->game->actResolveWolfTrap();

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[6]['location']);
    }

    public function testWolfTrapDisasterDoesNotWoundCharacters(): void
    {
        [$deckManager] = $this->prepareWolfTrapDisaster([
            'disaster_hunger' => 1,
            'disaster_break' => 1,
            'disaster_stress' => 0,
        ]);
        $deckManager->cards[25] = [
            'id' => 25,
            'location' => Location::CHARACTERS_IN_PLAY,
            'location_arg' => 0,
            'card_name' => 'Glenn',
            'is_character' => 1,
            'weakness_hunger' => 1,
            'weakness_break' => 1,
            'weakness_stress' => 0,
            'wounds' => 2,
        ];

        $this->game->actDrawWolfTrapDisaster();

        self::assertSame('resolve', $this->game->argWolfTrapChoice()['phase']);

        $this->game->actResolveWolfTrap();

        self::assertSame(2, $deckManager->cards[25]['wounds']);
        self::assertSame(
            Location::CHARACTERS_IN_PLAY,
            $deckManager->cards[25]['location']
        );
    }

    public function testWolfTrapBlackConsequencesResolveEscapeBeforeDisaster(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            6 => [
                'id' => 6,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Wolf Trap',
                'consequence_black' => [
                    'action' => 'multiple',
                    'number' => 2,
                    '0' => ['action' => 'avoid', 'avoid' => 'zombie'],
                    '1' => ['action' => 'wolftrap'],
                ],
            ],
            7 => [
                'id' => 7,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Clown',
                'is_zombie' => 1,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 6,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame([Transition::AVOID_ZOMBIE_CHOICE], $gamestate->transitions);
        self::assertSame(
            EventType::CONSEQUENCE,
            $eventStack->getCurrentEvent()['type']
        );
        self::assertSame(
            ['action' => 'wolftrap'],
            $eventStack->getCurrentEvent()['parameters']['consequence']
        );
    }

    public function testMultipleConsequencesAreStackedInReverseInsertionOrder(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            50 => [
                'id' => 50,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Multiple consequence card',
                'consequence_black' => [
                    'action' => 'multiple',
                    'number' => 2,
                    '0' => ['action' => 'bury', 'bury' => 'character'],
                    '1' => ['action' => 'bury', 'bury' => 'this'],
                ],
            ],
            51 => [
                'id' => 51,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'Character in hand',
                'is_character' => 1,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 50,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::BURY_CHARACTER_CHOICE],
            $gamestate->transitions
        );
        self::assertCount(2, $eventStack->events);
        self::assertSame(
            ['action' => 'bury', 'bury' => 'this'],
            $eventStack->events[0]['parameters']['consequence']
        );
        self::assertSame(
            EventType::BURY_CHARACTER_CHOICE,
            $eventStack->getCurrentEvent()['type']
        );
    }

    public function testWolfTrapConsequenceIsRejectedOutsideBlack(): void
    {
        $card = [
            'id' => 6,
            'consequence_grey' => ['action' => 'wolftrap'],
        ];

        $this->expectException(SystemException::class);
        $this->invoke('applyConsequences', [$card, 'grey']);
    }

    public function testAbsentAndConsequenceIgnoredDisasterCharacteristicsAreSkipped(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[6] = [
            'id' => 6,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Wolf Trap',
        ];
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards[2] = [
            'id' => 2,
            'type' => 2,
            'location' => 'deck',
            'location_arg' => 0,
            'disaster_hunger' => 1,
            'disaster_break' => 1,
            'disaster_stress' => 0,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::DISASTER_CHOICE, [
            'sourceCardId' => 6,
            'consequence' => [
                'action' => 'disasterignore',
                'number' => 1,
                'ignore' => 'hunger',
            ],
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->actDrawDisaster();
        $this->game->actConfirmDisasterDraw();

        $args = $this->game->argDisasterChoice();
        self::assertSame('characteristic', $args['phase']);
        self::assertSame('break', $args['characteristic']);

        $this->game->actConfirmDisasterCharacteristic();

        self::assertSame('complete', $this->game->argDisasterChoice()['phase']);
    }

    public function testDisasterConsequenceBuriesItsCardAfterConfirmationWithoutCharacters(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[8] = [
            'id' => 8,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Ellie and Joel',
            'consequence_grey' => [
                'action' => 'disaster',
                'number' => 1,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 8,
            'color' => 'grey',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 2);
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stEventDispatcher();

        self::assertFalse($eventStack->isEmpty());
        self::assertSame(
            [Transition::CARD_BURIAL_CONFIRMATION],
            $gamestate->transitions
        );
        self::assertSame(
            EventType::CARD_BURIAL_CONFIRMATION,
            $eventStack->getCurrentEvent()['type']
        );

        $this->game->actConfirmCardBurial();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[8]['location']);
        self::assertSame(
            [
                Transition::CARD_BURIAL_CONFIRMATION,
                Transition::DISPATCH_EVENTS,
            ],
            $gamestate->transitions
        );
    }

    public function testPendingDisasterChoiceDefensivelyBecomesACardBurialForAZeroCapacityCharacter(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[8] = [
            'id' => 8,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Ellie and Joel',
        ];
        // This state should be impossible during normal play: a character
        // reaching its wound limit must already have left the Characters area.
        $deckManager->cards[9] = [
            'id' => 9,
            'location' => Location::CHARACTERS_IN_PLAY,
            'location_arg' => 0,
            'card_name' => 'Glenn',
            'wounds' => 4,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::DISASTER_CHOICE, [
            'sourceCardId' => 8,
            'consequence' => [
                'action' => 'disaster',
                'number' => 1,
            ],
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 2);

        $this->game->stEventDispatcher();

        self::assertFalse($eventStack->isEmpty());
        self::assertSame(
            [Transition::CARD_BURIAL_CONFIRMATION],
            $gamestate->transitions
        );
        self::assertSame(
            EventType::CARD_BURIAL_CONFIRMATION,
            $eventStack->getCurrentEvent()['type']
        );
    }

    public function testBuryTopCardConsequencePausesForADeckChoice(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            40 => [
                'id' => 40,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Teacher',
                'consequence_black' => [
                    'action' => 'bury',
                    'bury' => 'topCard',
                ],
            ],
            1 => [
                'id' => 1,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Rural top',
            ],
            2 => [
                'id' => 2,
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban top',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 40,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 1);

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::BURY_TOP_CARD_CHOICE],
            $gamestate->transitions
        );
        self::assertSame(
            [Location::RURAL, Location::URBAN],
            $this->game->argBuryTopCardChoice()['availableDecks']
        );

        $this->game->actBuryTopCard(Location::RURAL);

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[1]['location']);
        self::assertSame(Location::URBAN, $deckManager->cards[2]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[40]['location']);
        self::assertSame(
            [
                Transition::BURY_TOP_CARD_CHOICE,
                Transition::GAME_END,
            ],
            $gamestate->transitions
        );
        self::assertSame('gameLoss', end($this->game->notify->events)['type']);
    }

    public function testBuryTopCardConsequenceUsesTheOnlyNonEmptyDeck(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            40 => [
                'id' => 40,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Teacher',
                'consequence_black' => [
                    'action' => 'bury',
                    'bury' => 'topCard',
                ],
            ],
            2 => [
                'id' => 2,
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban top',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);

        $outcome = $this->invoke(
            'applyConsequences',
            [$deckManager->cards[40], 'black']
        );

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[2]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[40]['location']);
        self::assertTrue($outcome['checkLoss']);
        self::assertSame([], $outcome['buryTopCardChoices']);
    }

    public function testBuryTopCardConsequenceBuriesSourceWhenBothDecksAreEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[40] = [
            'id' => 40,
            'location' => Location::ESCAPED,
            'location_arg' => 0,
            'card_name' => 'Teacher',
            'consequence_black' => [
                'action' => 'bury',
                'bury' => 'topCard',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);

        $outcome = $this->invoke(
            'applyConsequences',
            [$deckManager->cards[40], 'black']
        );

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[40]['location']);
        self::assertTrue($outcome['checkLoss']);
        self::assertSame([], $outcome['buryTopCardChoices']);
    }

    public function testBuryCharacterConsequencePausesForAHandCharacterChoice(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            34 => [
                'id' => 34,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'The reaper',
                'is_character' => '0',
                'consequence_black' => [
                    'action' => 'bury',
                    'bury' => 'character',
                ],
            ],
            8 => [
                'id' => 8,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Character',
                'is_character' => '1',
            ],
            9 => [
                'id' => 9,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Not a character',
                'is_character' => '0',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 34,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::BURY_CHARACTER_CHOICE],
            $gamestate->transitions
        );
        self::assertSame(
            [8],
            $this->game->argBuryCharacterChoice()['eligibleCardsIds']
        );

        $this->game->actBuryCharacter(8);

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[8]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[34]['location']);
        self::assertSame(
            [
                Transition::BURY_CHARACTER_CHOICE,
                Transition::DISPATCH_EVENTS,
            ],
            $gamestate->transitions
        );
    }

    public function testBuryCharacterConsequenceBuriesSourceWithoutAHandCharacter(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            34 => [
                'id' => 34,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'The reaper',
                'is_character' => '0',
                'consequence_black' => [
                    'action' => 'bury',
                    'bury' => 'character',
                ],
            ],
            9 => [
                'id' => 9,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Not a character',
                'is_character' => '0',
            ],
        ];
        $this->setProperty('deckManager', $deckManager);

        $outcome = $this->invoke(
            'applyConsequences',
            [$deckManager->cards[34], 'black']
        );

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[34]['location']);
        self::assertTrue($outcome['checkLoss']);
        self::assertSame([], $outcome['buryCharacterChoices']);
    }

    public function testBuryCharacterRejectsANonCharacterFromHand(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            8 => [
                'id' => 8,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Character',
                'is_character' => '1',
            ],
            9 => [
                'id' => 9,
                'location' => Location::HAND,
                'location_arg' => 0,
                'card_name' => 'Not a character',
                'is_character' => '0',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::BURY_CHARACTER_CHOICE, [
            'sourceCardId' => 34,
        ]);
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);

        $this->expectException(UserException::class);
        $this->game->actBuryCharacter(9);
    }

    public function testBiteConsequencePausesForACharacterChoice(): void
    {
        $deckManager = new class {
            public array $cards = [
                19 => [
                    'id' => 19,
                    'location' => Location::STORY_CURRENT,
                    'card_name' => 'Wild Zero',
                    'consequence_grey' => [
                        'action' => 'bite',
                        'bite' => '2',
                    ],
                ],
                8 => [
                    'id' => 8,
                    'location' => Location::CHARACTERS_IN_PLAY,
                    'card_name' => 'Ellie and Joel',
                    'wounds' => 0,
                ],
            ];

            public function getCard(int $cardId): ?array
            {
                return $this->cards[$cardId] ?? null;
            }

            public function getCardsInLocation(string $location): array
            {
                return array_values(array_filter(
                    $this->cards,
                    static function (array $card) use ($location): bool {
                        return $card['location'] === $location;
                    }
                ));
            }

            public function countCardInLocation(string $location): int
            {
                return count($this->getCardsInLocation($location));
            }

            public function setCardWounds(int $cardId, int $wounds): array
            {
                $this->cards[$cardId]['wounds'] = $wounds;
                return $this->cards[$cardId];
            }
        };
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 19,
            'color' => 'grey',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stEventDispatcher();

        self::assertSame([Transition::BITE_CHOICE], $gamestate->transitions);
        self::assertSame(2, $this->game->argBiteChoice()['bite']);
        self::assertSame(
            [8],
            $this->game->argBiteChoice()['eligibleCardsIds']
        );

        $this->game->actApplyBiteWounds('{"8":2}');

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(
            [Transition::BITE_CHOICE, Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
        self::assertSame(
            'characterWoundsChanged',
            end($this->game->notify->events)['type']
        );
        self::assertSame(2, $deckManager->cards[8]['wounds']);
    }

    public function testBiteConsequenceBuriesItsCardAfterConfirmationWithoutCapacity(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[13] = [
            'id' => 13,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Brigade',
            'consequence_grey' => [
                'action' => 'bite',
                'bite' => 2,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 13,
            'color' => 'grey',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 2);
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stEventDispatcher();

        self::assertFalse($eventStack->isEmpty());
        self::assertSame(
            [Transition::CARD_BURIAL_CONFIRMATION],
            $gamestate->transitions
        );
        self::assertSame(
            EventType::CARD_BURIAL_CONFIRMATION,
            $eventStack->getCurrentEvent()['type']
        );
        self::assertSame(
            'Brigade',
            $this->game->argCardBurialConfirmation()['card_name']
        );

        $this->game->actConfirmCardBurial();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[13]['location']);
        self::assertSame(
            [
                Transition::CARD_BURIAL_CONFIRMATION,
                Transition::DISPATCH_EVENTS,
            ],
            $gamestate->transitions
        );
    }

    public function testHealConsequenceLetsPlayerHealUpToItsMaximum(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            14 => [
                'id' => 14,
                'location' => Location::MEMORY,
                'location_arg' => 0,
                'card_name' => 'Bonfire',
                'consequence_grey' => ['action' => 'heal', 'number' => 2],
            ],
            8 => [
                'id' => 8,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Ellie and Joel',
                'wounds' => 2,
            ],
            9 => [
                'id' => 9,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Glenn',
                'wounds' => 0,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 14,
            'color' => 'grey',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame([Transition::HEAL_CHOICE], $gamestate->transitions);
        self::assertSame(2, $this->game->argHealChoice()['number']);
        self::assertSame([8, 9], $this->game->argHealChoice()['eligibleCardsIds']);

        $this->game->actHealCharacters('[8,9]');

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(1, $deckManager->cards[8]['wounds']);
        self::assertSame(0, $deckManager->cards[9]['wounds']);
        self::assertSame(
            [Transition::HEAL_CHOICE, Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
        self::assertSame(
            ['applyingConsequence', 'characterWoundsChanged'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testHealConditionsOnlyAllowMatchingWeaknesses(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            17 => [
                'id' => 17,
                'location' => Location::STORY_CURRENT,
                'location_arg' => 0,
                'card_name' => 'Cellar',
                'consequence_grey' => [
                    'action' => 'heal',
                    'number' => 3,
                    'condition' => 'hunger',
                    'condition2' => 'stress',
                ],
            ],
            8 => [
                'id' => 8,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Hungry character',
                'weakness_hunger' => 1,
                'weakness_break' => 0,
                'weakness_stress' => 0,
                'wounds' => 1,
            ],
            9 => [
                'id' => 9,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Stressed character',
                'weakness_hunger' => 0,
                'weakness_break' => 0,
                'weakness_stress' => 1,
                'wounds' => 1,
            ],
            10 => [
                'id' => 10,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Tired character',
                'weakness_hunger' => 0,
                'weakness_break' => 1,
                'weakness_stress' => 0,
                'wounds' => 1,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 17,
            'color' => 'grey',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [8, 9],
            $this->game->argHealChoice()['eligibleCardsIds']
        );

        $this->expectException(UserException::class);
        $this->game->actHealCharacters('[10]');
    }

    /**
     * @dataProvider invalidHealSelectionsProvider
     */
    public function testHealCharacterSelectionIsValidated(string $selection): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        foreach ([8, 9] as $cardId) {
            $deckManager->cards[$cardId] = [
                'id' => $cardId,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Character',
                'wounds' => 1,
            ];
        }
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::HEAL_CHOICE, [
            'sourceCardId' => 14,
            'number' => 1,
        ]);
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);

        $this->expectException(UserException::class);
        $this->game->actHealCharacters($selection);
    }

    public function invalidHealSelectionsProvider(): array
    {
        return [
            'more than the maximum' => ['[8,9]'],
            'duplicate character' => ['[8,8]'],
            'unknown character' => ['[99]'],
            'non-list JSON' => ['{"8":true}'],
        ];
    }

    /**
     * @dataProvider invalidBiteAllocationsProvider
     */
    public function testBiteWoundAllocationIsValidated(
        int $currentWounds,
        string $allocations
    ): void {
        $deckManager = new class($currentWounds) {
            public array $cards;

            public function __construct(int $currentWounds)
            {
                $this->cards = [
                    8 => [
                        'id' => 8,
                        'location' => Location::CHARACTERS_IN_PLAY,
                        'card_name' => 'Ellie and Joel',
                        'wounds' => $currentWounds,
                    ],
                ];
            }

            public function getCardsInLocation(string $location): array
            {
                return $location === Location::CHARACTERS_IN_PLAY
                    ? array_values($this->cards)
                    : [];
            }
        };
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::BITE_CHOICE, [
            'sourceCardId' => 19,
            'bite' => 2,
        ]);
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);

        $this->expectException(UserException::class);
        $this->game->actApplyBiteWounds($allocations);
    }

    public function invalidBiteAllocationsProvider(): array
    {
        return [
            'not every wound is assigned' => [0, '{"8":1}'],
            'character would exceed four wounds' => [3, '{"8":2}'],
            'unknown character' => [0, '{"99":2}'],
            'malformed character id' => [0, '{"8-invalid":2}'],
        ];
    }

    public function testAenorPreventsOneAllocatedBiteWoundOncePerGame(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            1 => [
                'id' => 1,
                'type' => '1',
                'type_arg' => '1',
                'location' => Location::PROTAGONIST,
                'location_arg' => 0,
                'card_name' => 'Aenor',
            ],
            8 => [
                'id' => 8,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Ellie and Joel',
                'wounds' => 0,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::BITE_CHOICE, [
            'sourceCardId' => 19,
            'bite' => 1,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        $args = $this->game->argBiteChoice();
        self::assertTrue($args['aenorAbilityAvailable']);
        self::assertSame([8], $args['aenorEligibleCharacterIds']);

        $this->game->actUseAenorAbility(8);

        self::assertSame(1, $this->game->getGameStateValue('aenorAbilityUsed'));
        self::assertSame(
            8,
            $this->game->getGameStateValue('aenorProtectedCharacterId')
        );
        self::assertFalse(
            $this->game->argBiteChoice()['aenorAbilityAvailable']
        );

        $this->game->actApplyBiteWounds('{"8":1}');

        self::assertSame(0, $deckManager->cards[8]['wounds']);
        self::assertSame(
            0,
            $this->game->getGameStateValue('aenorProtectedCharacterId')
        );
        self::assertSame(
            ['aenorAbilityUsed', 'aenorWoundPrevented'],
            array_column($this->game->notify->events, 'type')
        );
        self::assertSame(
            [Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
    }

    public function testAenorAbilityIsUnavailableForAnotherProtagonist(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[2] = [
            'id' => 2,
            'type' => '1',
            'type_arg' => '2',
            'location' => Location::PROTAGONIST,
            'location_arg' => 0,
            'card_name' => 'Boris',
        ];
        $this->setProperty('deckManager', $deckManager);

        self::assertFalse($this->invoke('aenorAbilityIsAvailable', [[
            'id' => 8,
            'card_name' => 'Glenn',
        ]]));
    }

    public function testAenorProtectionExpiresWhenAnotherCharacterIsWounded(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            1 => [
                'id' => 1,
                'type' => '1',
                'type_arg' => '1',
                'location' => Location::PROTAGONIST,
                'location_arg' => 0,
                'card_name' => 'Aenor',
            ],
            8 => [
                'id' => 8,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Glenn',
                'wounds' => 0,
            ],
            9 => [
                'id' => 9,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Murphy',
                'wounds' => 0,
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::BITE_CHOICE, [
            'sourceCardId' => 19,
            'bite' => 1,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->actUseAenorAbility(8);
        $this->game->actApplyBiteWounds('{"9":1}');

        self::assertSame(0, $deckManager->cards[8]['wounds']);
        self::assertSame(1, $deckManager->cards[9]['wounds']);
        self::assertSame(
            0,
            $this->game->getGameStateValue('aenorProtectedCharacterId')
        );
        self::assertSame(
            [
                'aenorAbilityUsed',
                'characterWoundsChanged',
                'aenorProtectionExpired',
            ],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testAenorPreventsOneDisasterWound(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            1 => [
                'id' => 1,
                'type' => '1',
                'type_arg' => '1',
                'location' => Location::PROTAGONIST,
                'location_arg' => 0,
                'card_name' => 'Aenor',
            ],
            8 => [
                'id' => 8,
                'location' => Location::CHARACTERS_IN_PLAY,
                'location_arg' => 0,
                'card_name' => 'Glenn',
                'weakness_hunger' => 1,
                'wounds' => 0,
            ],
        ];
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards[2] = [
            'id' => 2,
            'location' => 'hand',
            'location_arg' => 1,
            'disaster_hunger' => 1,
            'disaster_break' => 0,
            'disaster_stress' => 0,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::DISASTER_CHOICE, [
            'sourceCardId' => 20,
            'consequence' => ['action' => 'disaster', 'number' => 1],
            'resolution' => [
                'confirmedDraws' => 1,
                'pendingDrawCardId' => 0,
                'characteristicIndex' => 0,
                'ignoredCharacteristics' => [],
            ],
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $args = $this->game->argDisasterChoice();
        self::assertTrue($args['aenorAbilityAvailable']);
        self::assertSame([8], $args['aenorEligibleCharacterIds']);

        $this->game->actUseAenorAbility(8);
        $this->game->actConfirmDisasterCharacteristic();

        self::assertSame(0, $deckManager->cards[8]['wounds']);
        self::assertSame(
            0,
            $this->game->getGameStateValue('aenorProtectedCharacterId')
        );
        self::assertSame(
            ['aenorAbilityUsed', 'aenorWoundPrevented'],
            array_column($this->game->notify->events, 'type')
        );
        self::assertSame(
            [Transition::DISASTER_CHOICE],
            $gamestate->transitions
        );
    }

    public function testFourthWoundBuriesCharacterAndCausesImmediateLoss(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[8] = [
            'id' => 8,
            'location' => Location::CHARACTERS_IN_PLAY,
            'location_arg' => 0,
            'card_name' => 'Ellie and Joel',
            'wounds' => 3,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::BITE_CHOICE, [
            'sourceCardId' => 19,
            'bite' => 2,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 1);

        $this->game->actApplyBiteWounds('{"8":1}');

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::GRAVEYARD, $deckManager->cards[8]['location']);
        self::assertSame([Transition::GAME_END], $gamestate->transitions);
        self::assertSame(
            ['cardMoved', 'gameLoss'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testRobertMovesToDoneWhenHeReceivesHisThirdWound(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[12] = [
            'id' => 12,
            'location' => Location::CHARACTERS_IN_PLAY,
            'location_arg' => 0,
            'card_name' => 'Robert',
            'wounds' => 2,
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::BITE_CHOICE, [
            'sourceCardId' => 33,
            'bite' => 2,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 1);

        $this->game->actApplyBiteWounds('{"12":1}');

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(Location::DONE, $deckManager->cards[12]['location']);
        self::assertSame(2, $deckManager->cards[12]['wounds']);
        self::assertSame(
            [Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
        self::assertSame(
            ['cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testBrainstormOnlyOffersNonEmptyDecks(): void
    {
        $this->setProperty('deckManager', new class {
            public function countCardInLocation(string $location): int
            {
                return $location === Location::URBAN ? 2 : 0;
            }
        });

        self::assertSame(
            [Location::URBAN],
            $this->invoke('getAvailableDrawDecks')
        );
        self::assertSame(
            [Location::URBAN],
            $this->invoke('argBrainstormDeckChoice')['availableDecks']
        );
    }

    public function testFastMemoriseWaitsForADeckChoice(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            35 => [
                'id' => 35,
                'location' => Location::ESCAPED,
                'location_arg' => 0,
                'card_name' => 'Controller',
                'consequence_black' => [
                    'action' => 'fastmemorise',
                    'from' => 'deck',
                ],
            ],
            12 => [
                'id' => 12,
                'location' => Location::URBAN,
                'location_arg' => 1,
                'card_name' => 'Urban card',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 35,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::FAST_MEMORISE_DECK_CHOICE],
            $gamestate->transitions
        );
        self::assertSame(
            [Location::URBAN],
            $this->game->argFastMemoriseDeckChoice()['availableDecks']
        );
    }

    public function testFastMemoriseMovesTheTopTwoCardsToMemory(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            10 => [
                'id' => 10,
                'location' => Location::RURAL,
                'location_arg' => 3,
                'card_name' => 'Top card',
            ],
            11 => [
                'id' => 11,
                'location' => Location::RURAL,
                'location_arg' => 2,
                'card_name' => 'Second card',
            ],
            12 => [
                'id' => 12,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Third card',
            ],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        $this->game->actFastMemorise(Location::RURAL);

        self::assertSame(Location::MEMORY, $deckManager->cards[10]['location']);
        self::assertSame(Location::MEMORY, $deckManager->cards[11]['location']);
        self::assertSame(Location::RURAL, $deckManager->cards[12]['location']);
        self::assertSame(
            [[11, Location::MEMORY, 0], [10, Location::MEMORY, 0]],
            $deckManager->moves
        );
        self::assertSame(
            [Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
        self::assertSame(
            ['cardMoved', 'cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
    }

    public function testFastMemoriseFromHandWaitsForAHandChoice(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            36 => [
                'id' => 36,
                'location' => Location::GRAVEYARD,
                'location_arg' => 0,
                'card_name' => 'Zoey',
                'consequence_black' => [
                    'action' => 'fastmemorise',
                    'from' => 'hand',
                ],
            ],
            10 => [
                'id' => 10,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'Hand card',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 36,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::FAST_MEMORISE_HAND_CHOICE],
            $gamestate->transitions
        );
        self::assertSame(
            ['maximumCards' => 1],
            $this->game->argFastMemoriseHandChoice()
        );
    }

    public function testFastMemoriseFromHandIsSkippedWhenTheHandIsEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[36] = [
            'id' => 36,
            'location' => Location::GRAVEYARD,
            'location_arg' => 0,
            'card_name' => 'Zoey',
            'consequence_black' => [
                'action' => 'fastmemorise',
                'from' => 'hand',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 36,
            'color' => 'black',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame([Transition::STORY_CHECK], $gamestate->transitions);
    }

    public function testFastMemoriseMovesOneOrTwoHandCardsWithoutConsequences(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            10 => [
                'id' => 10,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'First card',
                'consequence_white' => ['action' => 'draw', 'number' => 1],
            ],
            11 => [
                'id' => 11,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'Second card',
                'consequence_white' => ['action' => 'bite', 'bite' => 2],
            ],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        $this->game->actFastMemoriseFromHand('[10,11]');

        self::assertSame(Location::MEMORY, $deckManager->cards[10]['location']);
        self::assertSame(Location::MEMORY, $deckManager->cards[11]['location']);
        self::assertSame(
            [[10, Location::MEMORY, 0], [11, Location::MEMORY, 0]],
            $deckManager->moves
        );
        self::assertSame(
            ['cardMoved', 'cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
        self::assertSame(
            [Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
    }

    public function testFastMemoriseFromHandRejectsMoreThanTwoCards(): void
    {
        $this->expectException(UserException::class);

        $this->game->actFastMemoriseFromHand('[1,2,3]');
    }

    public function testOptionalHandChoicesAcceptNoSelectedCards(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        $this->game->actFastMemoriseFromHand('[]');
        $this->game->actAvoidFromHand('[]');

        self::assertSame([], $deckManager->moves);
        self::assertSame(
            [Transition::DISPATCH_EVENTS, Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
        self::assertSame([], $this->game->notify->events);
    }

    /**
     * @dataProvider emptyDeckChoiceConsequenceProvider
     */
    public function testDeckChoiceConsequenceIsSkippedWhenBothDecksAreEmpty(
        string $action,
        string $color
    ): void {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[35] = [
            'id' => 35,
            'location' => $color === 'black'
                ? Location::ESCAPED
                : Location::MEMORY,
            'location_arg' => 0,
            'card_name' => 'Deck choice card',
            'consequence_' . $color => $action === 'fastmemorise'
                ? ['action' => $action, 'from' => 'deck']
                : ['action' => $action],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 35,
            'color' => $color,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame([Transition::STORY_CHECK], $gamestate->transitions);
    }

    public function emptyDeckChoiceConsequenceProvider(): array
    {
        return [
            'brainstorm' => ['brainstorm', 'white'],
            'fast memorise' => ['fastmemorise', 'black'],
        ];
    }

    public function testDrawResolvesBeforeFollowingAvoidHandChoice(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            37 => [
                'id' => 37,
                'location' => Location::MEMORY,
                'location_arg' => 0,
                'card_name' => 'Jill',
                'consequence_white' => [
                    'action' => 'multiple',
                    'number' => 2,
                    '0' => ['action' => 'draw', 'number' => 1],
                    '1' => ['action' => 'avoid', 'avoid' => 'hand2'],
                ],
            ],
            10 => [
                'id' => 10,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'Hand card',
            ],
            11 => [
                'id' => 11,
                'location' => Location::RURAL,
                'location_arg' => 1,
                'card_name' => 'Draw card',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 37,
            'color' => 'white',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;

        $this->game->stEventDispatcher();

        self::assertSame(
            [Transition::ADDITIONAL_DRAW_CARDS],
            $gamestate->transitions
        );
        self::assertSame(
            EventType::CONSEQUENCE,
            $eventStack->getCurrentEvent()['type']
        );
        self::assertSame(
            ['action' => 'avoid', 'avoid' => 'hand2'],
            $eventStack->getCurrentEvent()['parameters']['consequence']
        );

        $this->game->stEventDispatcher();

        self::assertSame(
            [
                Transition::ADDITIONAL_DRAW_CARDS,
                Transition::AVOID_HAND_CHOICE,
            ],
            $gamestate->transitions
        );
    }

    public function testAvoidHand2IsIgnoredWhenTheHandIsEmpty(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[37] = [
            'id' => 37,
            'location' => Location::MEMORY,
            'location_arg' => 0,
            'card_name' => 'Jill',
            'consequence_white' => [
                'action' => 'avoid',
                'avoid' => 'hand2',
            ],
        ];
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => 37,
            'color' => 'white',
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame([Transition::STORY_CHECK], $gamestate->transitions);
    }

    public function testAvoidFromHandMovesCardsToEscapedWithoutConsequences(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            10 => [
                'id' => 10,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'First card',
                'consequence_black' => ['action' => 'bite', 'bite' => 3],
            ],
            11 => [
                'id' => 11,
                'location' => Location::HAND,
                'location_arg' => 1,
                'card_name' => 'Second card',
                'consequence_black' => ['action' => 'disaster', 'number' => 1],
            ],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        $this->game->actAvoidFromHand('[10,11]');

        self::assertSame(Location::ESCAPED, $deckManager->cards[10]['location']);
        self::assertSame(Location::ESCAPED, $deckManager->cards[11]['location']);
        self::assertSame(
            [[10, Location::ESCAPED, 0], [11, Location::ESCAPED, 0]],
            $deckManager->moves
        );
        self::assertSame(
            ['cardMoved', 'cardMoved'],
            array_column($this->game->notify->events, 'type')
        );
        self::assertSame(
            [Transition::DISPATCH_EVENTS],
            $gamestate->transitions
        );
    }

    public function testAvoidFromHandRejectsMoreThanTwoCards(): void
    {
        $this->expectException(UserException::class);

        $this->game->actAvoidFromHand('[1,2,3]');
    }

    public function testZombieOrDieRequestsAChoiceWhenAZombieIsInHand(): void
    {
        $this->setProperty('deckManager', new class {
            public function getCardsInLocation(string $location): array
            {
                return $location === Location::HAND
                    ? [['id' => 12, 'is_zombie' => '1']]
                    : [];
            }
        });
        $card = [
            'id' => 33,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'zombieordie',
            ],
        ];

        self::assertSame([
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => true,
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'black']));
    }

    public function testZombieOrDieBuriesItsCardWithoutAZombieInHand(): void
    {
        $deckManager = new class {
            public array $cards = [
                33 => [
                    'id' => 33,
                    'location' => Location::ESCAPED,
                    'card_name' => 'Domitille',
                ],
            ];

            public function getCardsInLocation(string $location): array
            {
                return [];
            }

            public function getCard(int $cardId): array
            {
                return $this->cards[$cardId];
            }

            public function moveCard(
                int $cardId,
                string $location,
                int $locationArg = 0
            ): void {
                $this->cards[$cardId]['location'] = $location;
            }

            public function insertCardOnExtremePosition(
                int $cardId,
                string $location,
                bool $onTop
            ): void {
                $this->moveCard($cardId, $location);
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $card = [
            'id' => 33,
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'zombieordie',
            ],
        ];

        self::assertSame([
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => true,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => false,
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'black']));
        self::assertSame(
            Location::GRAVEYARD,
            $deckManager->cards[33]['location']
        );
    }

    public function testEscapeTallaChoiceRequiresAZombieOrMemoryCard(): void
    {
        $this->setProperty('deckManager', new class {
            public array $hand = [];
            public ?array $memoryTop = null;

            public function getCardsInLocation(string $location): array
            {
                return $location === Location::HAND ? $this->hand : [];
            }

            public function getCardOnTop(string $location): ?array
            {
                return $location === Location::MEMORY ? $this->memoryTop : null;
            }
        });

        self::assertFalse($this->invoke('escapeTallaChoiceIsAvailable'));

        $deckManager = $this->getProperty('deckManager');
        $deckManager->hand = [
            ['id' => 1, 'is_zombie' => '0'],
            ['id' => 2, 'is_zombie' => '1'],
        ];
        self::assertTrue($this->invoke('escapeTallaChoiceIsAvailable'));
        self::assertSame(
            [2],
            $this->invoke('argEscapeTallaChoice')['playableCardsIds']
        );
        self::assertFalse(
            $this->invoke('argEscapeTallaChoice')['mustChooseMemory']
        );
        self::assertSame(
            [2],
            $this->invoke('argAvoidZombieChoice')['playableCardsIds']
        );
        self::assertFalse(
            $this->invoke('argAvoidZombieChoice')['canChooseMemory']
        );

        $deckManager->hand = [];
        $deckManager->memoryTop = ['id' => 3];
        self::assertTrue($this->invoke('escapeTallaChoiceIsAvailable'));
        self::assertTrue(
            $this->invoke('argEscapeTallaChoice')['canChooseMemory']
        );
        self::assertTrue(
            $this->invoke('argEscapeTallaChoice')['mustChooseMemory']
        );
    }

    public function testLossConditionUsesGraveyardSize(): void
    {
        $this->game->setGameStateValue('lossCondition', 4);
        $this->setProperty('deckManager', new class {
            public function countCardInLocation(string $location): int
            {
                return $location === Location::GRAVEYARD ? 4 : 0;
            }
        });

        self::assertTrue($this->invoke('isLossReached'));
    }

    public function testStoryCheckWinsWhenMemoryAndCurrentCardAreEmpty(): void
    {
        $deckManager = new class {
            public array $cardsByLocation = [];

            public function countCardInLocation(string $location): int
            {
                return count($this->cardsByLocation[$location] ?? []);
            }

            public function getCardOnTop(string $location): ?array
            {
                $cards = $this->cardsByLocation[$location] ?? [];
                return $cards ? end($cards) : null;
            }
        };
        $this->setProperty('deckManager', $deckManager);

        self::assertTrue($this->invoke('checkWin'));

        $deckManager->cardsByLocation[Location::STORY_CURRENT] = [
            ['id' => 7],
        ];
        self::assertFalse($this->invoke('checkWin'));

        $deckManager->cardsByLocation[Location::STORY_CURRENT] = [];
        $deckManager->cardsByLocation[Location::MEMORY] = [
            ['id' => 8],
        ];
        self::assertFalse($this->invoke('checkWin'));
    }

    public function testStoryCheckVictorySetsPlayerScoreToTwenty(): void
    {
        $this->setProperty('deckManager', new class {
            public function countCardInLocation(string $location): int
            {
                return 0;
            }

            public function getCardOnTop(string $location): ?array
            {
                return null;
            }
        });
        $scoreCounter = new class {
            public array $values = [];

            public function setAll(int $value): void
            {
                $this->values[] = $value;
            }
        };
        $this->game->bga = (object) ['playerScore' => $scoreCounter];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stStoryCheckGameWinLoss();

        self::assertSame([20], $scoreCounter->values);
        self::assertSame([Transition::GAME_END], $gamestate->transitions);
        self::assertSame('gameWin', end($this->game->notify->events)['type']);
    }

    public function testStartingStoryCheckReversesTheMemoryTable(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['DbQuery'])
            ->getMock();
        $game->expects(self::once())
            ->method('DbQuery')
            ->with(self::callback(static function (string $query): bool {
                return strpos($query, 'UPDATE `twd_card`') !== false
                    && strpos($query, '`card_location_arg` = - `card_location_arg`') !== false
                    && strpos($query, "`card_location` = 'memory'") !== false;
            }));
        $deckManager = new class {
            public function getCardOnTop(string $location): ?array
            {
                return [
                    'id' => 18,
                    'type' => '2',
                    'type_arg' => '14',
                    'location' => Location::MEMORY,
                    'location_arg' => -1,
                    'card_name' => 'Teddy Bear',
                ];
            }
        };
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $game->notify = $this->game->notify;
        $game->gamestate = $gamestate;
        $reflection = new ReflectionProperty(Game::class, 'deckManager');
        $reflection->setAccessible(true);
        $reflection->setValue($game, $deckManager);
        $game->setGameStateValue('gamePhase', 1);

        $game->stStoryCheck();

        self::assertSame(2, $game->getGameStateValue('gamePhase'));
        self::assertSame([Transition::DEFAULT], $gamestate->transitions);
        $notification = end($game->notify->events);
        self::assertSame('storyCheckStarted', $notification['type']);
        self::assertSame(18, $notification['arguments']['memoryTopCard']['id']);
        self::assertSame('2', $notification['arguments']['memoryTopCard']['type']);
        self::assertTrue($notification['arguments']['memoryTopCard']['face_down']);
    }

    public function testStoryRevealKeepsTheRealNextMemoryCardFaceDown(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[15] = [
            'id' => 15,
            'type' => '2',
            'type_arg' => '11',
            'location' => Location::MEMORY,
            'location_arg' => 1,
            'card_name' => 'Horse',
        ];
        $deckManager->cards[25] = [
            'id' => 25,
            'type' => '3',
            'type_arg' => '3',
            'location' => Location::MEMORY,
            'location_arg' => 2,
            'card_name' => 'Glenn',
            'is_character' => '1',
            'consequence_grey' => ['action' => 'nothing'],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;

        $this->game->stStoryCheckStep();

        $notification = end($this->game->notify->events);
        self::assertSame('storyCardRevealed', $notification['type']);
        self::assertSame(25, $notification['arguments']['card']['id']);
        self::assertFalse($notification['arguments']['card']['face_down']);
        self::assertSame(15, $notification['arguments']['memoryTopCard']['id']);
        self::assertSame('2', $notification['arguments']['memoryTopCard']['type']);
        self::assertTrue($notification['arguments']['memoryTopCard']['face_down']);
    }

    public function testStoryCardWithoutGreyConsequenceIsBuriedWithoutConfirmation(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[15] = [
            'id' => 15,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Horse',
            'is_character' => '0',
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        $this->game->stStoryCheckStep();

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[15]['location']);
        self::assertSame(['gameCheck'], $gamestate->transitions);
        $notification = end($this->game->notify->events);
        self::assertSame('cardMoved', $notification['type']);
        self::assertSame(
            Location::STORY_CURRENT,
            $notification['arguments']['source']
        );
        self::assertSame(
            Location::GRAVEYARD,
            $notification['arguments']['destination']
        );
    }

    public function testResolvedStoryCharacterJoinsCharactersInPlay(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[8] = [
            'id' => 8,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Ellie and Joel',
            'is_character' => '1',
            'consequence_grey' => ['action' => 'nothing'],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;

        $this->game->stStoryCheckStep();
        self::assertSame(
            'applyGreyConsequence',
            $this->game->argStoryCheckPlayerChoice()['pendingAction']
        );
        $this->game->actStoryCheckPlayerChoice(8);
        $this->game->stEventDispatcher();
        $this->game->stStoryCheckStep();
        self::assertSame(
            'placeCharacter',
            $this->game->argStoryCheckPlayerChoice()['pendingAction']
        );
        $this->game->actStoryCheckPlayerChoice(8);

        self::assertSame(
            Location::CHARACTERS_IN_PLAY,
            $deckManager->cards[8]['location']
        );
        self::assertSame(
            [
                'playerChoice',
                Transition::DISPATCH_EVENTS,
                Transition::STORY_CHECK_STEP,
                'playerChoice',
                Transition::PHASE_2,
            ],
            $gamestate->transitions
        );
        $notification = end($this->game->notify->events);
        self::assertSame('characterPutInPlay', $notification['type']);
        self::assertSame(Location::STORY_CURRENT, $notification['arguments']['source']);
    }

    public function testStoryCheckAppliesGreyConsequenceOnlyWhenCardIsRevealed(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[16] = [
            'id' => 16,
            'location' => Location::MEMORY,
            'location_arg' => 1,
            'card_name' => 'RV',
            'consequence_grey' => [
                'action' => 'restore',
                'ressource' => 'ressource_break',
            ],
        ];
        $ressources = new class {
            public int $refillCount = 0;

            public function refillRessources(string $ressource): void
            {
                $this->refillCount++;
            }
        };
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('ressources', $ressources);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;

        $this->game->stStoryCheckStep();
        self::assertSame(0, $ressources->refillCount);
        self::assertSame(
            'applyGreyConsequence',
            $this->game->argStoryCheckPlayerChoice()['pendingAction']
        );
        $this->game->actStoryCheckPlayerChoice(16);
        self::assertSame(0, $ressources->refillCount);
        $this->game->stEventDispatcher();
        $this->game->stStoryCheckStep();

        self::assertSame(1, $ressources->refillCount);
        self::assertSame(
            Location::DONE,
            $deckManager->cards[16]['location']
        );
        self::assertSame(
            [
                'playerChoice',
                Transition::DISPATCH_EVENTS,
                Transition::STORY_CHECK_STEP,
                'gameCheck',
            ],
            $gamestate->transitions
        );
        self::assertSame(
            'storyCardResolved',
            end($this->game->notify->events)['type']
        );
    }

    public function testGreyConsequenceCanBuryCurrentCardAndCauseImmediateLoss(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[7] = [
            'id' => 7,
            'location' => Location::MEMORY,
            'location_arg' => 1,
            'card_name' => 'Clown',
            'consequence_grey' => [
                'action' => 'bury',
                'bury' => 'this',
            ],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 1);

        $this->game->stStoryCheckStep();
        $this->game->actStoryCheckPlayerChoice(7);
        $this->game->stEventDispatcher();

        self::assertSame(
            Location::GRAVEYARD,
            $deckManager->cards[7]['location']
        );
        self::assertSame(
            ['playerChoice', Transition::DISPATCH_EVENTS, Transition::GAME_END],
            $gamestate->transitions
        );
        self::assertSame(
            'gameLoss',
            end($this->game->notify->events)['type']
        );
    }

    public function testBuriedStoryCharacterDoesNotJoinCharactersInPlay(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[9] = [
            'id' => 9,
            'location' => Location::MEMORY,
            'location_arg' => 1,
            'card_name' => 'Kieren',
            'is_character' => '1',
            'consequence_grey' => [
                'action' => 'bury',
                'bury' => 'this',
            ],
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 2);
        $this->game->setGameStateValue('lossCondition', 5);

        $this->game->stStoryCheckStep();
        $this->game->actStoryCheckPlayerChoice(9);
        $this->game->stEventDispatcher();
        $this->game->stStoryCheckStep();

        self::assertSame(Location::GRAVEYARD, $deckManager->cards[9]['location']);
        self::assertNotContains(
            'characterPutInPlay',
            array_column($this->game->notify->events, 'type')
        );
        self::assertSame(
            [
                'playerChoice',
                Transition::DISPATCH_EVENTS,
                Transition::STORY_CHECK_STEP,
                'gameCheck',
            ],
            $gamestate->transitions
        );
    }

    public function testDeferredGreyConsequenceReturnsToStoryCheck(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[6] = [
            'id' => 6,
            'location' => Location::MEMORY,
            'location_arg' => 1,
            'card_name' => 'Wolf Trap',
            'consequence_grey' => [
                'action' => 'avoid',
                'avoid' => 'zombie',
            ],
        ];
        $deckManager->cards[41] = [
            'id' => 41,
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Zombie',
            'is_zombie' => '1',
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 2);

        $this->game->stStoryCheckStep();
        $this->game->actStoryCheckPlayerChoice(6);
        $this->game->stEventDispatcher();
        $this->game->actEscapeZombie(41);
        $this->game->stEventDispatcher();
        $this->game->stStoryCheckStep();

        self::assertSame(Location::ESCAPED, $deckManager->cards[41]['location']);
        self::assertSame(Location::DONE, $deckManager->cards[6]['location']);
        self::assertSame(
            [
                'playerChoice',
                Transition::DISPATCH_EVENTS,
                Transition::AVOID_ZOMBIE_CHOICE,
                Transition::DISPATCH_EVENTS,
                Transition::STORY_CHECK_STEP,
                'gameCheck',
            ],
            $gamestate->transitions
        );
    }

    public function testDeferredBlackConsequenceReturnsToPlayCards(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[6] = [
            'id' => 6,
            'type' => '2',
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Wolf Trap',
            'is_character' => '0',
            'consequence_black' => [
                'action' => 'avoid',
                'avoid' => 'zombie',
            ],
            'consequence_white' => null,
            'consequence_grey' => null,
        ];
        $deckManager->cards[41] = [
            'id' => 41,
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Zombie',
            'is_zombie' => '1',
        ];
        $deckManager->cards[42] = [
            'id' => 42,
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Remaining card',
            'is_zombie' => '0',
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->actPlayCard(6, Location::ESCAPED);
        $this->game->stEventDispatcher();
        $this->game->actEscapeZombie(41);
        $this->game->stEventDispatcher();

        self::assertSame(
            [
                Transition::DISPATCH_EVENTS,
                Transition::AVOID_ZOMBIE_CHOICE,
                Transition::DISPATCH_EVENTS,
                Transition::PLAY_CARDS,
            ],
            $gamestate->transitions
        );
    }

    public function testPlayingLastCardToMemoryStartsNormalRefillAfterConsequence(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[18] = [
            'id' => 18,
            'type' => '2',
            'location' => Location::HAND,
            'location_arg' => 0,
            'card_name' => 'Teddy Bear',
            'is_character' => '0',
            'consequence_black' => null,
            'consequence_white' => ['action' => 'nothing'],
            'consequence_grey' => null,
        ];
        $deckManager->cards[19] = [
            'id' => 19,
            'type' => '2',
            'location' => Location::RURAL,
            'location_arg' => 1,
            'card_name' => 'Next card',
        ];
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('eventStack', $this->createEventStack());
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('gamePhase', 1);

        $this->game->actPlayCard(18, Location::MEMORY);
        $this->game->stEventDispatcher();

        self::assertSame(0, $deckManager->countCardInLocation(Location::HAND));
        self::assertSame(Location::MEMORY, $deckManager->cards[18]['location']);
        self::assertSame(
            [Transition::DISPATCH_EVENTS, Transition::DRAW_CARDS],
            $gamestate->transitions
        );
    }

    public function testInvalidProtagonistCannotSetLossCondition(): void
    {
        $this->expectException(UserException::class);
        $this->invoke('setLossCondition', [[
            'id' => 42,
            'type' => 2,
            'type_arg' => 1,
            'losscon' => 5,
        ]]);
    }

    public function testDevelopmentActionIsRejectedInNormalMode(): void
    {
        $this->game->setGameStateValue('gameMode', 1);
        $this->expectException(UserException::class);
        $this->game->actGoToStoryCheck();
    }

    public function testGameModeUsesThePersistedGlobalValue(): void
    {
        $this->game->setGameStateValue('gameMode', 2);

        self::assertTrue($this->invoke('isTestMode'));
    }

    public function testNewGamesCurrentlyDefaultToTestMode(): void
    {
        self::assertSame(2, \Bga\Games\TheWalkingDeck\Constants\GameMode::DEFAULT);
    }

    private function invoke(string $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod(Game::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->game, $arguments);
    }

    private function prepareWolfTrapDisaster(array $characteristics): array
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[6] = [
            'id' => 6,
            'location' => Location::ESCAPED,
            'location_arg' => 0,
            'card_name' => 'Wolf Trap',
        ];
        $disasterManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $disasterManager->cards[1] = array_merge([
            'id' => 1,
            'type' => 4,
            'location' => 'deck',
            'location_arg' => 0,
        ], $characteristics);
        $eventStack = $this->createEventStack();
        $eventStack->pushEvent(EventType::WOLF_TRAP_CHOICE, [
            'sourceCardId' => 6,
        ]);
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->setProperty('disasterManager', $disasterManager);
        $this->setProperty('eventStack', $eventStack);
        $this->game->gamestate = $gamestate;
        $this->game->setGameStateValue('lossCondition', 5);

        return [$deckManager, $disasterManager, $eventStack, $gamestate];
    }

    private function setProperty(string $property, object $value): void
    {
        $reflection = new ReflectionProperty(Game::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($this->game, $value);
    }

    private function getProperty(string $property): object
    {
        $reflection = new ReflectionProperty(Game::class, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($this->game);
    }

    private function createEventStack(): object
    {
        return new class {
            public array $events = [];
            private int $nextId = 1;

            public function pushEvent(
                string $type,
                array $parameters = []
            ): void {
                $this->events[] = [
                    'id' => $this->nextId++,
                    'type' => $type,
                    'parameters' => $parameters,
                ];
            }

            public function getCurrentEvent(): ?array
            {
                return $this->events
                    ? $this->events[array_key_last($this->events)]
                    : null;
            }

            public function popEvent(): ?array
            {
                return array_pop($this->events);
            }

            public function updateEventParameters(int $eventId, array $parameters): void
            {
                foreach ($this->events as &$event) {
                    if ($event['id'] === $eventId) {
                        $event['parameters'] = $parameters;
                        return;
                    }
                }
            }

            public function isEmpty(): bool
            {
                return $this->events === [];
            }
        };
    }
}
