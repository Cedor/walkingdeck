<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Constants;

final class EventType
{
    public const CONSEQUENCE = 'consequence';
    public const BITE_CHOICE = 'biteChoice';
    public const CALAMITY_CHOICE = 'calamityChoice';
    public const DRAW_CARD = 'drawCard';
    public const SPECIAL_DRAW = 'specialDraw';
    public const ADDITIONAL_DRAW = 'additionalDraw';
    public const PLAY_CARD = 'playCard';
    public const NEXT_STATE = 'nextState';
}
