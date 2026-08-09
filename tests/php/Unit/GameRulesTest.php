<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\GameFramework\UserException;
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
        $gamestate = new class {
            public array $transitions = [];

            public function nextState(string $transition): void
            {
                $this->transitions[] = $transition;
            }
        };
        $this->setProperty('deckManager', $deckManager);
        $this->game->gamestate = $gamestate;

        $this->game->actPlayProtagonistCard(2);

        self::assertSame(Location::PROTAGONIST, $deckManager->cards[2]['location']);
        self::assertSame(Location::DISCARD, $deckManager->cards[3]['location']);
        self::assertSame(2, $this->game->getGameStateValue('difficultyLevel'));
        self::assertSame(5, $this->game->getGameStateValue('lossCondition'));
        self::assertSame([Transition::DEFAULT], $gamestate->transitions);
        self::assertSame('protagonistCardPlayed', end($this->game->notify->events)['type']);
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
            'brainstorm' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'disasterChoices' => [],
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
            'brainstorm' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'disasterChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'white']));
    }

    public function testUnearthMovesTheTopGraveyardCardToEscaped(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards = [
            41 => [
                'id' => 41,
                'location' => Location::GRAVEYARD,
                'location_arg' => 1,
                'card_name' => 'First buried card',
            ],
            42 => [
                'id' => 42,
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
        self::assertSame('cardMoved', end($this->game->notify->events)['type']);
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
            'brainstorm' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'disasterChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'black']));
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
            'brainstorm' => true,
            'biteChoices' => [],
            'healChoices' => [],
            'disasterChoices' => [],
        ], $this->invoke('applyConsequences', [$card, 'white']));
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
        self::assertSame('draw', $this->game->argDisasterChoice()['phase']);
        self::assertSame(1, $this->game->argDisasterChoice()['confirmedDraws']);
        $this->game->actDrawDisaster();
        self::assertSame('confirmDraw', $this->game->argDisasterChoice()['phase']);
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

    public function testDisasterConsequenceIsIgnoredWithoutCharacters(): void
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

        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(
            [Transition::STORY_CHECK_STEP],
            $gamestate->transitions
        );
    }

    public function testPendingDisasterChoiceIsSkippedWhenCharactersAreGone(): void
    {
        $deckManager = new \Bga\Games\TheWalkingDeck\Tests\Support\FakeCardDeck();
        $deckManager->cards[8] = [
            'id' => 8,
            'location' => Location::STORY_CURRENT,
            'location_arg' => 0,
            'card_name' => 'Ellie and Joel',
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

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(
            [Transition::STORY_CHECK_STEP],
            $gamestate->transitions
        );
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

    public function testBiteConsequenceIsIgnoredWithoutCharacters(): void
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

        $this->game->stEventDispatcher();

        self::assertTrue($eventStack->isEmpty());
        self::assertSame(
            [Transition::STORY_CHECK_STEP],
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
            $this->invoke('getBrainstormAvailableDecks')
        );
        self::assertSame(
            [Location::URBAN],
            $this->invoke('argBrainstormDeckChoice')['availableDecks']
        );
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
            'brainstorm' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'disasterChoices' => [],
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
            'brainstorm' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'disasterChoices' => [],
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

    public function testResolvedNonCharacterStoryCardMovesToDoneWithoutConfirmation(): void
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

        self::assertSame(Location::DONE, $deckManager->cards[15]['location']);
        self::assertSame(['gameCheck'], $gamestate->transitions);
        self::assertSame('storyCardResolved', end($this->game->notify->events)['type']);
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
            ['playerChoice', Transition::PHASE_2],
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

    private function invoke(string $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod(Game::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->game, $arguments);
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
