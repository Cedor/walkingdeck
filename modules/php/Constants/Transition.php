<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Constants;

final class Transition
{
    public const PHASE_1 = 'phase1';
    public const DRAW_CARDS = 'drawCards';
    public const PLAY_CARDS = 'playCards';
    public const ADDITIONAL_DRAW_CARDS = 'additionalDrawCards';
    public const STORY_CHECK = 'storyCheck';
    public const CONSEQUENCE = 'consequence';
    public const PLAYER_CHOICE = 'playerChoice';
    public const AVOID_ZOMBIE_CHOICE = 'avoidZombieChoice';
    public const BRAINSTORM_DECK_CHOICE = 'brainstormDeckChoice';
    public const BRAINSTORM_REORDER = 'brainstormReorder';
    public const STORY_CHECK_STEP = 'storyCheckStep';
    public const DEFAULT = '';
    public const GAME_END = 'gameEnd';
}
