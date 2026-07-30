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
        $this->game->stPhase1Switch();
        $this->game->stStoryCheckStep();
        $this->game->stStoryCheckStep();

        self::assertSame(1, $ressources->refillCount);
        self::assertSame(
            Location::STORY_CURRENT,
            $deckManager->cards[16]['location']
        );
        self::assertSame(
            [
                Transition::PHASE_1,
                Transition::STORY_CHECK_STEP,
                'playerChoice',
                'playerChoice',
            ],
            $gamestate->transitions
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
        $this->game->stPhase1Switch();

        self::assertSame(
            Location::GRAVEYARD,
            $deckManager->cards[7]['location']
        );
        self::assertSame(
            [Transition::PHASE_1, Transition::GAME_END],
            $gamestate->transitions
        );
        self::assertSame(
            'gameLoss',
            end($this->game->notify->events)['type']
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
        $this->game->stPhase1Switch();
        $this->game->actEscapeZombie(41);
        $this->game->stPhase1Switch();
        $this->game->stStoryCheckStep();

        self::assertSame(Location::ESCAPED, $deckManager->cards[41]['location']);
        self::assertSame(
            [
                Transition::PHASE_1,
                Transition::AVOID_ZOMBIE_CHOICE,
                Transition::PHASE_1,
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
        $this->game->stPhase1Switch();
        $this->game->actEscapeZombie(41);
        $this->game->stPhase1Switch();

        self::assertSame(
            [
                Transition::PHASE_1,
                Transition::AVOID_ZOMBIE_CHOICE,
                Transition::PHASE_1,
                Transition::PLAY_CARDS,
            ],
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
