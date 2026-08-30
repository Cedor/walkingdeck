<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Tests\Unit;

use Bga\Games\TheWalkingDeck\CardTextTranslations;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testProtagonistDefeatTextsUseNumberArguments(): void
    {
        $dbmodel = file_get_contents(dirname(__DIR__, 3) . '/dbmodel.sql');

        self::assertIsString($dbmodel);
        self::assertDoesNotMatchRegularExpression(
            '/"text"\s*:\s*"\d+ peripeteia in the graveyard"/',
            $dbmodel
        );
        preg_match_all(
            '/"defeat"\s*:\s*\{\s*'
                . '"text"\s*:\s*"\$\{number\} peripeteia in the graveyard"\s*,\s*'
                . '"args"\s*:\s*\{\s*"number"\s*:\s*([345])\s*\}\s*\}/',
            $dbmodel,
            $matches
        );

        self::assertSame(['5', '5', '4', '3'], $matches[1]);
    }

    public function testCardTextTranslationMarkersMatchDatabaseTexts(): void
    {
        $dbmodel = file_get_contents(dirname(__DIR__, 3) . '/dbmodel.sql');

        self::assertIsString($dbmodel);
        preg_match_all('/"text"\s*:\s*"([^"]*)"/', $dbmodel, $matches);
        $databaseTexts = array_values(array_unique(array_filter(
            $matches[1],
            static function (string $text): bool {
                return preg_match(
                    '/^(?:\$\{[A-Za-z0-9_]+\}\s*)+$/',
                    $text
                ) !== 1;
            }
        )));
        $translationMarkers = array_values(array_unique(
            CardTextTranslations::getAll()
        ));
        sort($databaseTexts);
        sort($translationMarkers);

        self::assertSame($databaseTexts, $translationMarkers);
    }

    public function testBiteCardTextsUseTheirConfiguredNumberArgument(): void
    {
        $dbmodel = file_get_contents(dirname(__DIR__, 3) . '/dbmodel.sql');

        self::assertIsString($dbmodel);
        self::assertDoesNotMatchRegularExpression(
            '/"text"\s*:\s*"\d+ \$\{bite\}"/',
            $dbmodel
        );
        preg_match_all(
            '/\'\{"action"\s*:\s*"bite",\s*"bite"\s*:\s*(\d+)\}\'.*?'
                . '"text"\s*:\s*"\$\{number\} \$\{bite\}"\s*,\s*'
                . '"args"\s*:\s*\{\s*"number"\s*:\s*(\d+)/',
            $dbmodel,
            $matches,
            PREG_SET_ORDER
        );

        self::assertCount(9, $matches);
        foreach ($matches as $match) {
            self::assertSame($match[1], $match[2]);
        }
    }

    public function testHealCardTextsUseTheirConfiguredNumberArgument(): void
    {
        $dbmodel = file_get_contents(dirname(__DIR__, 3) . '/dbmodel.sql');

        self::assertIsString($dbmodel);
        self::assertDoesNotMatchRegularExpression(
            '/"text"\s*:\s*"\$\{heal\} up to \d+ characters/',
            $dbmodel
        );
        preg_match_all(
            '/\'\{"action"\s*:\s*"heal",\s*"number"\s*:\s*(\d+)[^\']*\}\'.*?'
                . '"text"\s*:\s*"\$\{heal\} up to \$\{number\} characters[^\"]*"\s*,\s*'
                . '"args"\s*:\s*\{[^}]*\}\s*,\s*"number"\s*:\s*(\d+)/',
            $dbmodel,
            $matches,
            PREG_SET_ORDER
        );

        self::assertCount(8, $matches);
        foreach ($matches as $match) {
            self::assertSame($match[1], $match[2]);
        }
    }

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
