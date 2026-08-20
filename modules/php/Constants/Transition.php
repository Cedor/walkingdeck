<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Constants;

final class Transition
{
    public const DISPATCH_EVENTS = 'dispatchEvents';
    public const DRAW_CARDS = 'drawCards';
    public const PLAY_CARDS = 'playCards';
    public const ADDITIONAL_DRAW_CARDS = 'additionalDrawCards';
    public const STORY_CHECK = 'storyCheck';
    public const CONSEQUENCE = 'consequence';
    public const PLAYER_CHOICE = 'playerChoice';
    public const AVOID_ZOMBIE_CHOICE = 'avoidZombieChoice';
    public const BRAINSTORM_DECK_CHOICE = 'brainstormDeckChoice';
    public const BRAINSTORM_REORDER = 'brainstormReorder';
    public const UNREMEMBER_CHOICE = 'unrememberChoice';
    public const BITE_CHOICE = 'biteChoice';
    public const CARD_BURIAL_CONFIRMATION = 'cardBurialConfirmation';
    public const HEAL_CHOICE = 'healChoice';
    public const DISASTER_CHOICE = 'disasterChoice';
    public const WOLF_TRAP_CHOICE = 'wolfTrapChoice';
    public const RECOVER_CHOICE = 'recoverChoice';
    public const FAST_MEMORISE_DECK_CHOICE = 'fastMemoriseDeckChoice';
    public const FAST_MEMORISE_HAND_CHOICE = 'fastMemoriseHandChoice';
    public const AVOID_HAND_CHOICE = 'avoidHandChoice';
    public const BURY_CHARACTER_CHOICE = 'buryCharacterChoice';
    public const BURY_TOP_CARD_CHOICE = 'buryTopCardChoice';
    public const DRAFT_DISASTER_CHOICE = 'draftDisasterChoice';
    public const AVOID_DECK_CHOICE = 'avoidDeckChoice';
    public const STORY_CHECK_STEP = 'storyCheckStep';
    public const DEFAULT = '';
    public const GAME_END = 'gameEnd';
}
