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


$machinestates = [

    // The initial state. Please do not modify.

    1 => array(
        "name" => "gameSetup",
        "description" => "",
        "type" => "manager",
        "action" => "stGameSetup",
        "transitions" => ["" => 2]
    ),

    // Note: ID=2 => your first state

    2 => [
        "name" => "protagonistChoice",
        "description" => clienttranslate('You must pick a protagonist'),
        "descriptionmyturn" => clienttranslate('You must pick a protagonist'),
        "type" => "activePlayer",
        "args" => "argPlayerTurn",
        "possibleactions" => [
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actPlayProtagonistCard", 
        ],
        "transitions" => ["" => 3]
    ],

    3 => [
        "name" => "drawCards",
        "description" => clienttranslate('You must draw cards'),
        "descriptionmyturn" => clienttranslate('You must draw cards'),
        "type" => "activePlayer",
        "possibleactions" => [
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actDrawUrbanCard",
            "actDrawRuralCard",
        ],
        "updateGameProgression" => true,
        "transitions" => ["" => 4]
    ],
    4 => [
        "name" => "playCards",
        "description" => clienttranslate('You must play cards'),
        "descriptionmyturn" => clienttranslate('You must play cards'),
        "type" => "activePlayer",
        "possibleactions" => [
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actPlayCard",
            "actPass"
        ],
        "transitions" => ["nextTurn" => 3, "storyCheck" => 5]
    ],
    5 => [
        "name" => "storyCheck",
        "description" => clienttranslate('Story will be checked'),
        "type" => "game",
        "action" => "stStoryCheck",
        "transitions" => ["" => 6]
    ],
    6 => [
        "name" => "storyCheckStep",
        "description" => clienttranslate('Story check step'),
        "type" => "game",
        "action" => "stStoryCheckStep",
        "transitions" => ["playerChoice" => 7, "gameCheck" => 8]
    ],
    7 => [
        "name" => "storyCheckPlayerChoice",
        "description" => clienttranslate('Story check player choice'),
        "type" => "activePlayer",
        "possibleactions" => [
            // these actions are called from the front with bgaPerformAction, and matched to the function on the game.php file
            "actStoryCheckPlayerChoice",
        ],
        "transitions" => ["" => 8]
    ],
    8 => [
        "name" => "storyCheckGame",
        "description" => clienttranslate('Story check game win/loss'),
        "type" => "game",
        "action" => "stStoryCheckGameWinLoss",
        "transitions" => ["nextStep" => 6, "gameEnd" => 99]
    ],

    // Final state.
    // Please do not modify (and do not overload action/args methods).
    99 => [
        "name" => "gameEnd",
        "description" => clienttranslate("End of game"),
        "type" => "manager",
        "action" => "stGameEnd",
        "args" => "argGameEnd"
    ],

];



