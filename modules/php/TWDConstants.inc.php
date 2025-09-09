<?php
// TWDConstants.inc.php
declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck\TWDState {
  // States
  const ProtagonistSelection = 2;
  const DrawCards = 3;
  const AdditionalDraw = 31;
  const PlayCards = 4;
  const StoryCheck = 5;
  const StoryCheckStep = 6;
  const StoryPlayerChoice = 7;
  const StoryCheckWinLoss = 8;
  const GameEnd = 99;
}

namespace Bga\Games\TheWalkingDeck\TWDCardType {
  // Card types
  const Protagonist = 1;
  const Rural = 2;
  const Urban = 3;
}

namespace Bga\Games\TheWalkingDeck\TWDTransition {
  // Transitions
  const DrawCards = 'drawCards';
  const PlayCards = 'playCards';
  const AdditionalDrawCards = 'additionalDrawCards';
  const StoryCheck = 'storyCheck';
  const DefaultTransition = '';
  const GameEnd = 'gameEnd';
}

namespace Bga\Games\TheWalkingDeck\TWDLocation {
  // Locations (MUST match SQL and JS)
  const Hand = 'hand';
  const Rural = 'deck_rural';
  const Urban = 'deck_urban';
  const Escaped = 'escaped';
  const Graveyard = 'graveyard';
  const Discard = 'discard';
  const Memory = 'memory';
  const CharactersInPlay = 'characters';
  const Done = 'done';
  const Protagonist = 'protagonist';
}
