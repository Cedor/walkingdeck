<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\Games\TheWalkingDeck\Tests\Support\FakeGame;
use Bga\Games\TheWalkingDeck\TWDRessources;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TWDRessourcesTest extends TestCase
{
    private FakeGame $game;
    private TWDRessources $resources;

    protected function setUp(): void
    {
        $this->game = new FakeGame();
        $this->resources = new TWDRessources($this->game);
    }

    public function testResourcesAreInitializedAsAvailable(): void
    {
        $this->resources->initRessources();

        self::assertSame([
            1 => ['id' => 'ressource_hunger', 'consumed' => 0],
            2 => ['id' => 'ressource_break', 'consumed' => 0],
            3 => ['id' => 'ressource_stress', 'consumed' => 0],
        ], $this->resources->getRessources());
    }

    public function testConsumeAndRefillAreIdempotentAndNotifyOnce(): void
    {
        $this->resources->consumeRessources('ressource_hunger');
        $this->resources->consumeRessources('ressource_hunger');

        self::assertSame(1, $this->resources->getRessourceState('ressource_hunger'));
        self::assertCount(1, $this->game->notify->events);
        self::assertSame('ressourceConsumed', $this->game->notify->events[0]['type']);

        $this->resources->refillRessources('ressource_hunger');
        $this->resources->refillRessources('ressource_hunger');

        self::assertSame(0, $this->resources->getRessourceState('ressource_hunger'));
        self::assertCount(2, $this->game->notify->events);
        self::assertSame('ressourceRefilled', $this->game->notify->events[1]['type']);
    }

    /**
     * @dataProvider invalidStateProvider
     */
    public function testInvalidStateIsRejected(string $resourceId, int $state): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resources->setRessourceState($resourceId, $state);
    }

    public function invalidStateProvider(): array
    {
        return [
            'empty identifier' => ['', 0],
            'negative state' => ['ressource_hunger', -1],
            'state above one' => ['ressource_hunger', 2],
        ];
    }
}
