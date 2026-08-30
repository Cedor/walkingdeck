<?php

/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * TheWalkingDeck implementation : © <Cedor> <cedordev@gmail.com>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * states.inc.php
 *
 * TheWalkingDeck game states description
 *
 */

/*
   Game state machine is a tool used to facilitate game developpement by doing common stuff that can be set up
   in a very easy way from this configuration file.

   Please check the BGA Studio presentation about game state to understand this, and associated documentation.

   Summary:

   States types:
   _ activeplayer: in this type of state, we expect some action from the active player.
   _ multipleactiveplayer: in this type of state, we expect some action from multiple players (the active players)
   _ game: this is an intermediary state where we don't expect any actions from players. Your game logic must decide what is the next game state.
   _ manager: special type for initial and final state

   Arguments of game states:
   _ name: the name of the GameState, in order you can recognize it on your own code.
   _ description: the description of the current game state is always displayed in the action status bar on
                  the top of the game. Most of the time this is useless for game state with "game" type.
   _ descriptionmyturn: the description of the current game state when it's your turn.
   _ type: defines the type of game states (activeplayer / multipleactiveplayer / game / manager)
   _ action: name of the method to call when this game state become the current game state. Usually, the
             action method is prefixed by "st" (ex: "stMyGameStateName").
   _ possibleactions: array that specify possible player actions on this step. It allows you to use "checkAction"
                      method on both client side (Javacript: this.checkAction) and server side (PHP: $this->checkAction).
   _ transitions: the transitions are the possible paths to go from a game state to another. You must name
                  transitions in order to use transition names in "nextState" PHP method, and use IDs to
                  specify the next game state for each transition.
   _ args: name of the method to call to retrieve arguments for this gamestate. Arguments are sent to the
           client side to be used on "onEnteringState" or to set arguments in the gamestate description.
   _ updateGameProgression: when specified, the game progression is updated (=> call to your getGameProgression
                            method).
*/

//    !! It is not a good idea to modify this file when a game is running !!
use Bga\Games\TheWalkingDeck\Constants\GameStep;
use Bga\Games\TheWalkingDeck\Constants\Transition;
use Bga\GameFramework\GameStateBuilder;
use Bga\GameFramework\StateType;

$machinestates = [

    // ID=2 => your first state
    GameStep::PROTAGONIST_SELECTION => GameStateBuilder::create()
        ->name('protagonistSelection')
        ->description(clienttranslate("You must pick a protagonist"))
        ->descriptionmyturn(clienttranslate("You must pick a protagonist"))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            'actPlayProtagonistCard',
        ])
        ->transitions([
            Transition::DEFAULT => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::EVENT_DISPATCHER => GameStateBuilder::create()
        ->name(Transition::DISPATCH_EVENTS)
        ->description('')
        ->type(StateType::GAME)
        ->action('stEventDispatcher')
        ->transitions([
            Transition::DRAW_CARDS => GameStep::DRAW_CARDS,
            Transition::ADDITIONAL_DRAW_CARDS => GameStep::ADDITIONAL_DRAW,
            Transition::PLAY_CARDS => GameStep::PLAY_CARDS,
            Transition::STORY_CHECK => GameStep::STORY_CHECK,
            Transition::PLAYER_CHOICE => GameStep::PLAYER_CHOICE_1,
            Transition::AVOID_ZOMBIE_CHOICE => GameStep::AVOID_ZOMBIE_CHOICE,
            Transition::BRAINSTORM_DECK_CHOICE => GameStep::BRAINSTORM_DECK_CHOICE,
            Transition::UNREMEMBER_CHOICE => GameStep::UNREMEMBER_CHOICE,
            Transition::BITE_CHOICE => GameStep::BITE_CHOICE,
            Transition::CARD_BURIAL_CONFIRMATION => GameStep::CARD_BURIAL_CONFIRMATION,
            Transition::ADRIEN_RESOURCE_CHOICE => GameStep::ADRIEN_RESOURCE_CHOICE,
            Transition::HEAL_CHOICE => GameStep::HEAL_CHOICE,
            Transition::DISASTER_CHOICE => GameStep::DISASTER_CHOICE,
            Transition::WOLF_TRAP_CHOICE => GameStep::WOLF_TRAP_CHOICE,
            Transition::RECOVER_CHOICE => GameStep::RECOVER_CHOICE,
            Transition::FAST_MEMORISE_DECK_CHOICE => GameStep::FAST_MEMORISE_DECK_CHOICE,
            Transition::FAST_MEMORISE_HAND_CHOICE => GameStep::FAST_MEMORISE_HAND_CHOICE,
            Transition::AVOID_HAND_CHOICE => GameStep::AVOID_HAND_CHOICE,
            Transition::BURY_CHARACTER_CHOICE => GameStep::BURY_CHARACTER_CHOICE,
            Transition::BURY_TOP_CARD_CHOICE => GameStep::BURY_TOP_CARD_CHOICE,
            Transition::DRAFT_DISASTER_CHOICE => GameStep::DRAFT_DISASTER_CHOICE,
            Transition::AVOID_DECK_CHOICE => GameStep::AVOID_DECK_CHOICE,
            Transition::STORY_CHECK_STEP => GameStep::STORY_CHECK_STEP,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::DRAW_CARDS => GameStateBuilder::create()
        ->name('drawCards')
        ->description(clienttranslate("You must draw card(s)"))
        ->descriptionmyturn(clienttranslate("You must draw card(s)"))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            'actDrawFromDeck',
        ])
        ->transitions([
            Transition::DRAW_CARDS => GameStep::DRAW_CARDS,
            Transition::ADDITIONAL_DRAW_CARDS => GameStep::ADDITIONAL_DRAW,
            Transition::PLAY_CARDS => GameStep::PLAY_CARDS,
            Transition::STORY_CHECK => GameStep::STORY_CHECK,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::ADDITIONAL_DRAW => GameStateBuilder::create()
        ->name('specialDraw')
        ->description(clienttranslate("You must draw card(s)"))
        ->descriptionmyturn(clienttranslate("You must draw card(s)"))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            'actDrawFromDeck',
        ])
        ->transitions([
            Transition::ADDITIONAL_DRAW_CARDS => GameStep::ADDITIONAL_DRAW,
            Transition::DRAW_CARDS => GameStep::DRAW_CARDS,
            Transition::PLAY_CARDS => GameStep::PLAY_CARDS,
            Transition::STORY_CHECK => GameStep::STORY_CHECK,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::PLAY_CARDS => GameStateBuilder::create()
        ->name(Transition::PLAY_CARDS)
        ->description(clienttranslate("You must play card(s)"))
        ->descriptionmyturn(clienttranslate("You must play card(s)"))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            'actPlayCard',
            'actRefillHand',
        ])
        ->transitions([
            Transition::PLAY_CARDS => GameStep::PLAY_CARDS,
            Transition::DRAW_CARDS => GameStep::DRAW_CARDS,
            Transition::ADDITIONAL_DRAW_CARDS => GameStep::ADDITIONAL_DRAW,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END
        ])
        ->build(),
    GameStep::PLAYER_CHOICE_1 => GameStateBuilder::create()
        ->name('escapeTallaChoice')
        ->description(clienttranslate('You must choose a card from your hand or the top card of the memory to escape'))
        ->descriptionmyturn(clienttranslate('You must choose a card from your hand or the top card of the memory to escape'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argEscapeTallaChoice')
        ->possibleactions([
            'actEscapeZombie',
            'actEscapeTallaFromMemory',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::AVOID_ZOMBIE_CHOICE => GameStateBuilder::create()
        ->name('avoidZombieChoice')
        ->description(clienttranslate('You must choose a zombie from your hand to escape'))
        ->descriptionmyturn(clienttranslate('You must choose a zombie from your hand to escape'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argAvoidZombieChoice')
        ->possibleactions([
            'actEscapeZombie',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::BRAINSTORM_DECK_CHOICE => GameStateBuilder::create()
        ->name('brainstormDeckChoice')
        ->description(clienttranslate('You must choose a deck'))
        ->descriptionmyturn(clienttranslate('You must choose a deck'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argBrainstormDeckChoice')
        ->possibleactions([
            'actStartBrainstorm',
        ])
        ->transitions([
            Transition::BRAINSTORM_REORDER => GameStep::BRAINSTORM_REORDER,
        ])
        ->build(),
    GameStep::BRAINSTORM_REORDER => GameStateBuilder::create()
        ->name('brainstormReorder')
        ->description(clienttranslate('You must reorder the cards'))
        ->descriptionmyturn(clienttranslate('You must reorder the cards'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argBrainstormReorder')
        ->possibleactions([
            'actConfirmBrainstorm',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::BITE_CHOICE => GameStateBuilder::create()
        ->name(Transition::BITE_CHOICE)
        ->description(clienttranslate('The active player must assign ${bite} wound(s)'))
        ->descriptionmyturn(clienttranslate('You must assign ${bite} wound(s) to your characters'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argBiteChoice')
        ->possibleactions([
            'actApplyBiteWounds',
            'actUseAenorAbility',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::CARD_BURIAL_CONFIRMATION => GameStateBuilder::create()
        ->name(Transition::CARD_BURIAL_CONFIRMATION)
        ->description(clienttranslate('Card ${card_name} will be buried'))
        ->descriptionmyturn(clienttranslate('Card ${card_name} will be buried'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argCardBurialConfirmation')
        ->possibleactions([
            'actConfirmCardBurial',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::ADRIEN_RESOURCE_CHOICE => GameStateBuilder::create()
        ->name(Transition::ADRIEN_RESOURCE_CHOICE)
        ->description(clienttranslate('The active player must remove a resource'))
        ->descriptionmyturn(clienttranslate('You must choose a resource to remove from the game'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argAdrienResourceChoice')
        ->possibleactions([
            'actRemoveAdrienResource',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::HEAL_CHOICE => GameStateBuilder::create()
        ->name(Transition::HEAL_CHOICE)
        ->description(clienttranslate('The active player may choose up to ${number} character(s) to heal'))
        ->descriptionmyturn(clienttranslate('You may choose up to ${number} character(s) to heal'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argHealChoice')
        ->possibleactions([
            'actHealCharacters',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::DISASTER_CHOICE => GameStateBuilder::create()
        ->name(Transition::DISASTER_CHOICE)
        ->description(clienttranslate('${actplayer} must resolve a disaster'))
        ->descriptionmyturn(clienttranslate('You must resolve a disaster'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argDisasterChoice')
        ->possibleactions([
            'actDrawDisaster',
            'actUseDisasterResource',
            'actConfirmDisasterCharacteristic',
            'actResolveDisaster',
            'actUseAenorAbility',
        ])
        ->transitions([
            Transition::DISASTER_CHOICE => GameStep::DISASTER_CHOICE,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::UNREMEMBER_CHOICE => GameStateBuilder::create()
        ->name(Transition::UNREMEMBER_CHOICE)
        ->description(clienttranslate('${actplayer} may recover up to two cards from Memory'))
        ->descriptionmyturn(clienttranslate('You may recover up to two of cards from Memory'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argUnrememberChoice')
        ->possibleactions([
            'actConfirmUnremember',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::WOLF_TRAP_CHOICE => GameStateBuilder::create()
        ->name(Transition::WOLF_TRAP_CHOICE)
        ->description(clienttranslate('${actplayer} must draw a disasterfor Wolf Trap'))
        ->descriptionmyturn(clienttranslate('You must draw a disaster for Wolf Trap'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argWolfTrapChoice')
        ->possibleactions([
            'actDrawWolfTrapDisaster',
            'actResolveWolfTrap',
        ])
        ->transitions([
            Transition::WOLF_TRAP_CHOICE => GameStep::WOLF_TRAP_CHOICE,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::RECOVER_CHOICE => GameStateBuilder::create()
        ->name(Transition::RECOVER_CHOICE)
        ->description(clienttranslate('${actplayer} must recover an escaped card'))
        ->descriptionmyturn(clienttranslate('You must choose an escaped card to recover'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argRecoverChoice')
        ->possibleactions([
            'actRecoverCard',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::FAST_MEMORISE_DECK_CHOICE => GameStateBuilder::create()
        ->name(Transition::FAST_MEMORISE_DECK_CHOICE)
        ->description(clienttranslate('${actplayer} must choose a deck'))
        ->descriptionmyturn(clienttranslate('You must choose a deck'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argFastMemoriseDeckChoice')
        ->possibleactions([
            'actFastMemorise',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::FAST_MEMORISE_HAND_CHOICE => GameStateBuilder::create()
        ->name(Transition::FAST_MEMORISE_HAND_CHOICE)
        ->description(clienttranslate('${actplayer} must choose up to two cards from their hand to memorise'))
        ->descriptionmyturn(clienttranslate('You may choose up to two cards from your hand to memorise'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argFastMemoriseHandChoice')
        ->possibleactions([
            'actFastMemoriseFromHand',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::AVOID_HAND_CHOICE => GameStateBuilder::create()
        ->name(Transition::AVOID_HAND_CHOICE)
        ->description(clienttranslate('${actplayer} must choose up to two cards from their hand to escape'))
        ->descriptionmyturn(clienttranslate('You may choose up to two cards from your hand to escape'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argAvoidHandChoice')
        ->possibleactions([
            'actAvoidFromHand',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::BURY_CHARACTER_CHOICE => GameStateBuilder::create()
        ->name(Transition::BURY_CHARACTER_CHOICE)
        ->description(clienttranslate('${actplayer} must choose a character from their hand to bury'))
        ->descriptionmyturn(clienttranslate('You must choose a character from your hand to bury'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argBuryCharacterChoice')
        ->possibleactions([
            'actBuryCharacter',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::BURY_TOP_CARD_CHOICE => GameStateBuilder::create()
        ->name(Transition::BURY_TOP_CARD_CHOICE)
        ->description(clienttranslate('${actplayer} must choose a deck'))
        ->descriptionmyturn(clienttranslate('You must choose a deck'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argBuryTopCardChoice')
        ->possibleactions([
            'actBuryTopCard',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::DRAFT_DISASTER_CHOICE => GameStateBuilder::create()
        ->name(Transition::DRAFT_DISASTER_CHOICE)
        ->description(clienttranslate('${actplayer} must draft a disaster'))
        ->descriptionmyturn(clienttranslate('You must draw two disasters and choose one to destroy'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argDraftDisasterChoice')
        ->possibleactions([
            'actDrawDraftDisasters',
            'actResolveDraftDisaster',
        ])
        ->transitions([
            Transition::DRAFT_DISASTER_CHOICE => GameStep::DRAFT_DISASTER_CHOICE,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::AVOID_DECK_CHOICE => GameStateBuilder::create()
        ->name(Transition::AVOID_DECK_CHOICE)
        ->description(clienttranslate('${actplayer} must choose a deck'))
        ->descriptionmyturn(clienttranslate('You must choose a deck'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argAvoidDeckChoice')
        ->possibleactions([
            'actAvoidDeck',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::STORY_CHECK => GameStateBuilder::create()
        ->name('storyCheck')
        ->description(clienttranslate("Story will be checked"))
        ->type(StateType::GAME)
        ->action('stStoryCheck')
        ->transitions([
            Transition::DEFAULT => GameStep::STORY_CHECK_STEP
        ])
        ->build(),
    GameStep::STORY_CHECK_STEP => GameStateBuilder::create()
        ->name('storyCheckStep')
        ->description(clienttranslate("Story check step"))
        ->type(StateType::GAME)
        ->action('stStoryCheckStep')
        ->transitions([
            'playerChoice' => GameStep::STORY_PLAYER_CHOICE,
            'gameCheck' => GameStep::STORY_CHECK_WIN_LOSS,
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
            Transition::GAME_END => GameStep::GAME_END,
        ])
        ->build(),
    GameStep::STORY_PLAYER_CHOICE => GameStateBuilder::create()
        ->name('storyCheckPlayerChoice')
        ->description(clienttranslate('${actplayer} must confirm the Story Check action'))
        ->descriptionmyturn(clienttranslate("You must confirm the Story Check action"))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argStoryCheckPlayerChoice')
        ->possibleactions([
            'actStoryCheckPlayerChoice',
        ])
        ->transitions([
            Transition::DISPATCH_EVENTS => GameStep::EVENT_DISPATCHER,
        ])
        ->build(),
    GameStep::STORY_CHECK_WIN_LOSS => GameStateBuilder::create()
        ->name('storyCheckGame')
        ->description(clienttranslate("Story check game win/loss"))
        ->type(StateType::GAME)
        ->action('stStoryCheckGameWinLoss')
        ->transitions([
            'nextStep' => GameStep::STORY_CHECK_STEP,
            'gameEnd' => GameStep::GAME_END
        ])
        ->build()
];

foreach ($machinestates as $stateId => &$state) {
    if (
        !in_array($stateId, [
            GameStep::PROTAGONIST_SELECTION,
            GameStep::DISASTER_CHOICE,
            GameStep::STORY_PLAYER_CHOICE,
        ], true)
        && $state->possibleActions !== null
    ) {
        $state->possibleActions[] = 'actUseBorisResource';
    }
}
unset($state);
