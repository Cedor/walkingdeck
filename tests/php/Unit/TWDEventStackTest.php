<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\GameFramework\SystemException;
use Bga\Games\TheWalkingDeck\Constants\EventType;
use Bga\Games\TheWalkingDeck\Game;
use Bga\Games\TheWalkingDeck\TWDEventStack;
use PHPUnit\Framework\TestCase;

final class TWDEventStackTest extends TestCase
{
    public function testPushSerializesAndInsertsAnEvent(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['escapeStringForDB', 'DbQuery'])
            ->getMock();
        $game->method('escapeStringForDB')->willReturnCallback('addslashes');
        $game->expects(self::once())
            ->method('DbQuery')
            ->with(self::callback(static function (string $query): bool {
                return strpos($query, EventType::DRAW_CARD) !== false
                    && strpos($query, '\\"source\\":\\"deck_rural\\"') !== false;
            }));

        (new TWDEventStack($game))->pushEvent(EventType::DRAW_CARD, ['source' => 'deck_rural']);
    }

    public function testCurrentEventDecodesParameters(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObjectFromDB'])
            ->getMock();
        $game->method('getObjectFromDB')->willReturn([
            'event_id' => '12',
            'event_type' => EventType::CONSEQUENCE,
            'event_parameters' => '{"cardId":7,"color":"grey"}',
        ]);

        self::assertSame([
            'id' => 12,
            'type' => EventType::CONSEQUENCE,
            'parameters' => ['cardId' => 7, 'color' => 'grey'],
        ], (new TWDEventStack($game))->getCurrentEvent());
    }

    public function testPopUsesLifoEventAndDeletesIt(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObjectFromDB', 'DbQuery'])
            ->getMock();
        $game->method('getObjectFromDB')->willReturn([
            'event_id' => '9',
            'event_type' => EventType::PLAY_CARD,
            'event_parameters' => null,
        ]);
        $game->expects(self::once())
            ->method('DbQuery')
            ->with(self::stringContains('WHERE `event_id` = 9'));

        $event = (new TWDEventStack($game))->popEvent();

        self::assertSame(9, $event['id']);
        self::assertSame([], $event['parameters']);
    }

    public function testEmptyStackReturnsNullAndReportsEmpty(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObjectFromDB', 'getUniqueValueFromDB'])
            ->getMock();
        $game->method('getObjectFromDB')->willReturn(null);
        $game->method('getUniqueValueFromDB')->willReturn('0');
        $stack = new TWDEventStack($game);

        self::assertNull($stack->getCurrentEvent());
        self::assertNull($stack->popEvent());
        self::assertTrue($stack->isEmpty());
    }

    public function testUnknownEventTypeIsRejected(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObjectFromDB'])
            ->getMock();
        $game->method('getObjectFromDB')->willReturn([
            'event_id' => '1',
            'event_type' => 'unknown',
            'event_parameters' => null,
        ]);

        $this->expectException(SystemException::class);
        (new TWDEventStack($game))->getCurrentEvent();
    }

    public function testInvalidJsonIsRejected(): void
    {
        $game = $this->getMockBuilder(Game::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObjectFromDB'])
            ->getMock();
        $game->method('getObjectFromDB')->willReturn([
            'event_id' => '1',
            'event_type' => EventType::NEXT_STATE,
            'event_parameters' => '{invalid',
        ]);

        $this->expectException(\JsonException::class);
        (new TWDEventStack($game))->getCurrentEvent();
    }
}
