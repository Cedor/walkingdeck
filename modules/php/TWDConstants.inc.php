<?php
// TWDConstants.inc.php
declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck {
  const TWDHandSize = 3;
}

namespace Bga\Games\TheWalkingDeck\TWDState {
  // States
  const ProtagonistSelection = 2;
  const Phase1 = 3;
  const DrawCards = 31;
  const AdditionalDraw = 32;
  const PlayCards = 33;
  const Consequence1 = 34;
  const PlayerChoice1 = 35;
  const StoryCheck = 5;
  const StoryCheckStep = 51;
  const StoryPlayerChoice = 52;
  const StoryCheckWinLoss = 53;
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
  const Phase1 = 'phase1';
  const DrawCards = 'drawCards';
  const PlayCards = 'playCards';
  const AdditionalDrawCards = 'additionalDrawCards';
  const StoryCheck = 'storyCheck';
  const Consequence = 'consequence';
  const PlayerChoice = 'playerChoice';
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

namespace Bga\Games\TheWalkingDeck\TWDEventType {
  const Consequence = 'consequence';
  const DrawCard = 'drawCard';
  const SpecialDraw = 'specialDraw';
  const AdditionalDraw = 'additionalDraw';
  const PlayCard = 'playCard';
  const NextState = 'nextState';
  const ForcePass = 'forcePass';
}
