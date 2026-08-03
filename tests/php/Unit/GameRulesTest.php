<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\GameFramework\UserException;
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
        ], $this->invoke('applyConsequences', [$card, 'white']));
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
        ], $this->invoke('applyConsequences', [$card, 'white']));
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

    public function testResolvedStoryCardMovesToDone(): void
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

        self::assertSame(
            'resolveCard',
            $this->game->argStoryCheckPlayerChoice()['pendingAction']
        );
        $this->game->actStoryCheckPlayerChoice(15);

        self::assertSame(Location::DONE, $deckManager->cards[15]['location']);
        self::assertSame([Transition::PHASE_2], $gamestate->transitions);
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

        self::assertSame(
            'placeCharacter',
            $this->game->argStoryCheckPlayerChoice()['pendingAction']
        );
        $this->game->actStoryCheckPlayerChoice(8);

        self::assertSame(
            Location::CHARACTERS_IN_PLAY,
            $deckManager->cards[8]['location']
        );
        self::assertSame([Transition::PHASE_2], $gamestate->transitions);
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
                'ressource' => 'ressource2',
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
            Location::STORY_CURRENT,
            $deckManager->cards[16]['location']
        );
        self::assertSame(
            [
                'playerChoice',
                Transition::DISPATCH_EVENTS,
                Transition::STORY_CHECK_STEP,
                'playerChoice',
            ],
            $gamestate->transitions
        );
        self::assertSame(
            'resolveCard',
            $this->game->argStoryCheckPlayerChoice()['pendingAction']
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
        self::assertSame(
            [
                'playerChoice',
                Transition::DISPATCH_EVENTS,
                Transition::AVOID_ZOMBIE_CHOICE,
                Transition::DISPATCH_EVENTS,
                Transition::STORY_CHECK_STEP,
                'playerChoice',
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

            public function isEmpty(): bool
            {
                return $this->events === [];
            }
        };
    }
}
