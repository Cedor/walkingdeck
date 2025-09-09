<?php
// TWDConstants.inc.php
declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\TWDState{
// States
const ST_PROTAGONIST_SELECTION = 2;
const ST_DRAW_CARDS = 3;
const ST_SPECIAL_DRAW = 31;
const ST_PLAY_CARDS = 4;
const ST_STORY_CHECK = 5;
const ST_STORY_CHECK_STEP = 6;
const ST_STORY_PLAYER_CHOICE = 7;
const ST_STORY_CHECK_GAME = 8;
const ST_GAME_END = 99;
}

namespace Bga\Games\TheWalkingDeck\TWDCardType{
// Card types
const Protagonist = 1;
const Rural = 2;
const Urban = 3;
}

namespace Bga\Games\TheWalkingDeck\TWDTransition{
// Transitions
const drawCards = 'drawCards';
const playCards = 'playCards';
const drawSpecialCards = 'drawSpecialCards';
const storyCheck = 'storyCheck';
}

namespace Bga\Games\TheWalkingDeck\TWDLocation{
// Locations (match SQL and JS)
const Hand = 'hand';
const Rural = 'deck_rural';
const Urban = 'deck_urban';
const Escaped = 'escaped';
const Discard = 'discard';
const Memory = 'memory';
const CharactersInPlay = 'characters';
const Done = 'done';
const Protagonist = 'protagonist';
}