<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testBgaDescriptionVariablesAreNotInterpolatedByPhp(): void
    {
        $states = file_get_contents(dirname(__DIR__, 3) . '/states.inc.php');

        self::assertIsString($states);
        self::assertStringNotContainsString('clienttranslate("${', $states);
    }

    public function testBorisResourceActionIsAvailableDuringPlayerStates(): void
    {
        $states = file_get_contents(dirname(__DIR__, 3) . '/states.inc.php');

        self::assertIsString($states);
        self::assertStringContainsString(
            '$state->possibleActions[] = \'actUseBorisResource\';',
            $states
        );
        self::assertStringNotContainsString(
            '$state[\'possibleactions\'][] = \'actUseBorisResource\';',
            $states
        );
        self::assertStringContainsString(
            'GameStep::DISASTER_CHOICE,',
            $states
        );
        self::assertStringContainsString(
            'GameStep::STORY_PLAYER_CHOICE,',
            $states
        );
    }
}
