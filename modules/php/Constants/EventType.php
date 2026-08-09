<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Constants;

final class EventType
{
    public const CONSEQUENCE = 'consequence';
    public const BITE_CHOICE = 'biteChoice';
    public const HEAL_CHOICE = 'healChoice';
    public const BURY_CHARACTER_CHOICE = 'buryCharacterChoice';
    public const DISASTER_CHOICE = 'disasterChoice';
    public const WOLF_TRAP_CHOICE = 'wolfTrapChoice';
    public const RECOVER_CHOICE = 'recoverChoice';
    public const DRAW_CARD = 'drawCard';
    public const SPECIAL_DRAW = 'specialDraw';
    public const ADDITIONAL_DRAW = 'additionalDraw';
    public const PLAY_CARD = 'playCard';
    public const NEXT_STATE = 'nextState';
}
