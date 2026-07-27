<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Constants;

final class GameStep
{
    public const PROTAGONIST_SELECTION = 2;
    public const PHASE_1 = 3;
    public const DRAW_CARDS = 31;
    public const ADDITIONAL_DRAW = 32;
    public const PLAY_CARDS = 33;
    public const CONSEQUENCE_1 = 34;
    public const PLAYER_CHOICE_1 = 35;
    public const STORY_CHECK = 5;
    public const STORY_CHECK_STEP = 51;
    public const STORY_PLAYER_CHOICE = 52;
    public const STORY_CHECK_WIN_LOSS = 53;
    public const GAME_END = 99;
}
