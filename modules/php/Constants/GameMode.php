<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\Constants;

final class GameMode
{
    public const GLOBAL_ID = 20;
    public const NORMAL = 1;
    public const TEST = 2;

    // Change this one value to NORMAL when new games must become normal.
    public const DEFAULT = self::TEST;
}
