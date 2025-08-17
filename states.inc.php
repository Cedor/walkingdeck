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
use Bga\GameFramework\GameStateBuilder;
use Bga\GameFramework\StateType;

$machinestates = [

    // ID=2 => your first state
    2 => GameStateBuilder::create()
        ->name('protagonistChoice')
        ->description(clienttranslate('You must pick a protagonist'))
        ->descriptionmyturn(clienttranslate('You must pick a protagonist'))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actPlayProtagonistCard",
        ])
        ->transitions([
            "" => 3
        ])
        ->build(),
    3 => GameStateBuilder::create()
        ->name('drawCards')
        ->description(clienttranslate('You must draw cards'))
        ->descriptionmyturn(clienttranslate('You must draw cards'))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actDrawFromRuralDeck",
            "actDrawFromUrbanDeck",
        ])
        ->transitions([
            "drawAnotherCard" => 3,
            "ready" => 4
    ])
        ->build(),
    4 => GameStateBuilder::create()
        ->name('playCards')
        ->description(clienttranslate('You must play cards'))
        ->descriptionmyturn(clienttranslate('You must play cards'))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actPlayCard",
            "actPass"
        ])
        ->transitions([
            "keepPlaying" => 4,
            "nextTurn" => 3,
            "storyCheck" => 5
        ])
        ->build(),
    5 => GameStateBuilder::create()
        ->name('storyCheck')
        ->description(clienttranslate('Story will be checked'))
        ->type(StateType::GAME)
        ->action('stStoryCheck')
        ->transitions([
            "" => 6
        ])
        ->build(),
    6 => GameStateBuilder::create()
        ->name('storyCheckStep')
        ->description(clienttranslate('Story check step'))
        ->type(StateType::GAME)
        ->action('stStoryCheckStep')
        ->transitions([
            "playerChoice" => 7,
            "gameCheck" => 8
        ])
        ->build(),
    7 => GameStateBuilder::create()
        ->name('storyCheckPlayerChoice')
        ->description(clienttranslate('Story check player choice'))
        ->type(StateType::ACTIVE_PLAYER)
        ->possibleactions([
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actStoryCheckPlayerChoice",
        ])
        ->transitions([
            "" => 8
        ])
        ->build(),
    8 => GameStateBuilder::create()
        ->name('storyCheckGame')
        ->description(clienttranslate('Story check game win/loss'))
        ->type(StateType::GAME)
        ->action('stStoryCheckGameWinLoss')

        ->transitions([
            "nextStep" => 6,
            "gameEnd" => 99
        ])
        ->build(),

    // Final state.
    // Please do not modify (and do not overload action/args methods).
    99 => GameStateBuilder::create()
        ->name('gameEnd')
        ->description(clienttranslate('End of game'))
        ->type(StateType::MANAGER)
        ->action('stGameEnd')
        ->args('argGameEnd')
        ->build()
];



