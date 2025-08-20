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
 * Game.php
 *
 * This is the main file for your game logic.
 *
 * In this PHP file, you are going to defines the rules of the game.
 */

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck;

require_once(APP_GAMEMODULE_PATH . "module/table/table.game.php");

class Game extends \Table
{
    /**
     * Your global variables labels:
     *
     * Here, you can assign labels to global variables you are using for this game. You can use any number of global
     * variables with IDs between 10 and 99. If your game has options (variants), you also have to associate here a
     * label to the corresponding ID in `gameoptions.inc.php`.
     *
     * NOTE: afterward, you can get/set the global variables with `getGameStateValue`, `setGameStateInitialValue` or
     * `setGameStateValue` functions.
     */

    public $cards;
    protected $twdDeck;

    public function __construct()
    {
        parent::__construct();

        $this->initGameStateLabels([
            "difficultyLevel" => 10, // example of game option
            "gamePhase" => 11, // 1=> expedition phase, 2=> story phase
            "lossCondition" => 12,
        ]);

        $this->cards = $this->getNew("module.common.deck");
        $this->cards->init("card");
        $this->twdDeck = new TWDDeck($this);

        /* example of notification decorator.
        // automatically complete notification args when needed
        $this->notify->addDecorator(function(string $message, array $args) {
            if (isset($args['player_id']) && !isset($args['player_name']) && str_contains($message, '${player_name}')) {
                $args['player_name'] = $this->getPlayerNameById($args['player_id']);
            }
        
            if (isset($args['card_id']) && !isset($args['card_name']) && str_contains($message, '${card_name}')) {
                $args['card_name'] = self::$CARD_TYPE[$args['card_id']]['card_name'];
                $args['i18n'][] = ['card_name'];
            }
            
            return $args;
        });*/
    }

    private function setDifficulty(int $difficulty): void
    {
        $this->setGameStateValue('difficultyLevel', $difficulty);
    }

    private function setGamePhase(int $phase): void
    {
        $this->setGameStateValue('gamePhase', $phase);
    }

    /**
     * Player action, play a card from hand
     *
     * @throws BgaUserException
     */
    public function actPlayCard(int $card_id, string $location): void
    {
        $card = $this->twdDeck->getCard($card_id);
        $card_name = $card['card_name'];
        if ($card["location"] == "hand" && ($card["type"] == 2 || $card["type"] == 3) && $this->cardCanBePlayedInLocation($card, $location)) {
            $this->twdDeck->insertCardOnExtremePosition($card_id, $location, true);
            $card = $this->twdDeck->getCard($card_id);
            $card_name = $card['card_name'];
            $this->notify->all("cardPlayed", \clienttranslate("Card $card_name played from hand to $location"), array(
                "card" => $card,
                "location" => $location
            ));
        } else {
            throw new \BgaUserException($this->_("Illegal Move: ") . "$card_name ($card_id) cannot be played from hand to location $location");
        }
        $this->actPass();
    }

    private function cardCanBePlayedInLocation(array $card, string $location): bool
    {
        // Check if the card can be played in the specified location
        // For example, you might want to check if the location is valid for the card type
        // Here we assume that all cards can be played in any location for simplicity
        return true;
    }

    /**
     * Player action, pass turn (only allowed if cards in decks), auto call when hand is empty
     *
     * @throws BgaUserException
     */
    public function actPass(bool $force = false): void
    {
        /* Check board state 
            if both decks empty
            then if hand = 0 then start story check else return to same state et play a card
            else start new turn
        */
        if ($force && $this->twdDeck->countCardInLocation("hand") > 2)
            throw new \BgaUserException($this->_("You can't pass, play some cards first."));
        if ($force && $this->twdDeck->countCardInLocation("deck_rural") == 0 && $this->twdDeck->countCardInLocation("deck_urban") == 0)
            throw new \BgaUserException($this->_("You can't pass, no cards left to draw."));
        if ($this->twdDeck->countCardInLocation("deck_rural") == 0 && $this->twdDeck->countCardInLocation("deck_urban") == 0) {
            if ($this->twdDeck->countCardInLocation("hand") == 0)
                // start story check
                $this->gamestate->nextState("storyCheck");
            else {
                // stay in same state
                $this->gamestate->nextState("keepPlaying");
            }
        } else {
            if ($this->twdDeck->countCardInLocation("hand") == 0 || $force) {
                // start new turn
                $this->gamestate->nextState("nextTurn");
            } else {
                // stay in same state
                $this->gamestate->nextState("keepPlaying");
            }
        }
    }

    private function setLossCondition(array $card): int
    {
        // TODO: implement loss condition check
        $card_type = intval($card["type"]);
        $card_type_arg = intval($card["type_arg"]);
        if ($card_type !== 1 || $card_type_arg < 1 || $card_type_arg > 4)
            throw new \BgaUserException($this->_("Illegal call to setLossCondition with") . $card);
        $lossCon = $card["losscon"];
        $this->setGameStateValue("lossCondition", $lossCon);
        return $lossCon;
    }

    /**
     * Player action, pick a protagonist. Defines game difficulty.
     *
     * @throws BgaUserException
     */
    public function actPlayProtagonistCard(int $card_id): void
    {
        $card = $this->twdDeck->getCard($card_id);
        if ($card['type'] == '1' && $card["location"] == "hand" && $this->twdDeck->countCardInLocation("protagonist") == 0) {
            $this->twdDeck->moveCard($card_id, "protagonist");
            $card = $this->twdDeck->getCard($card_id);
            $difficulty = intval($card["type_arg"]);
            $this->setDifficulty($difficulty);
            $cardname = $card["card_name"];
            //set loss condition
            $lossCon = $this->setLossCondition($card);
            $this->twdDeck->moveAllCardsInLocation("hand", "discard");
            $this->notify->all("protagonistCardPlayed", \clienttranslate("Protagonist $cardname played, loss condition: $lossCon event buried"), array(
                "card" => $card,
                "difficulty" => $difficulty,
                "lossCondition" => $lossCon,
            ));
        } else throw new \BgaUserException($this->_("Illegal Move: ") . "you plays $card_id from Hand to protagonist slot");

        // at the end of the action, move to the next state
        $this->gamestate->nextState("");
    }

    /**
     * Player action : drawing a card from Rural deck
     *
     * @throws BgaUserException
     */
    public function actDrawFromRuralDeck(): void
    {
        if ($this->twdDeck->countCardInLocation('deck_rural') == 0) {
            throw new \BgaUserException($this->_("Illegal Move: ") . "No card left in rural deck");
        }
        $cardPicked = $this->twdDeck->pickCard("deck_rural", 0);
        $this->notify->all("cardDrawnFromRuralDeck", \clienttranslate("Card drawn from rural deck"), array(
            "card" => $cardPicked
        ));
        $this->checkHand();
    }
    /**
     * Player action : drawing a card from Urban deck
     *
     * @throws BgaUserException
     */
    public function actDrawFromUrbanDeck(): void
    {
        if ($this->twdDeck->countCardInLocation("deck_urban") == 0) {
            throw new \BgaUserException($this->_("Illegal Move: ") . "No card left in urban deck");
        }
        $cardPicked = $this->twdDeck->pickCard("deck_urban", 0);
        $this->notify->all("cardDrawnFromUrbanDeck", \clienttranslate("Card drawn from urban deck"), array(
            "card" => $cardPicked
        ));
        $this->checkHand();
    }
    private function checkHand(): void
    {
        if ($this->twdDeck->countCardInLocation('hand') > 2 || ($this->twdDeck->countCardInLocation('deck_rural') == 0 && $this->twdDeck->countCardInLocation('deck_urban') == 0)) {
            $this->gamestate->nextState("ready");
        } else {
            $this->gamestate->nextState("drawAnotherCard");
        }
    }

    /**
     * TODO : remove after tests
     *
     * @throws BgaUserException
     */
    public function actGoToStoryCheck(): void
    {

        $this->gamestate->nextState("storyCheck");
    }

    /**
     * Player action : resolve effects during a story check step
     *
     * @throws BgaUserException
     */
    public function actStoryCheckPlayerChoice(int $card_id = null): void
    {
        // TODO remove after tests
        $this->notify->all("actionPicked", \clienttranslate("You have picked an action"), array());
        $this->gamestate->nextState("");
    }

    /**
     * Game state arguments, example content.
     *
     * This method returns some additional information that is very specific to the `playerTurn` game state.
     *
     * @return array
     * @see ./states.inc.php
     */
    public function argPlayerTurn(): array
    {
        // Get some values from the current game situation from the database.

        return [
            "playableCardsIds" => [1, 2],
        ];
    }

    /**
     * Compute and return the current game progression.
     *
     * The number returned must be an integer between 0 and 100.
     *
     * This method is called each time we are in a game state with the "updateGameProgression" property set to true.
     *
     * @return int
     * @see ./states.inc.php
     */
    public function getGameProgression()
    {
        // TODO: compute and return the game progression

        return 0;
    }

    /**
     * Game state action, start the second phase of the game
     */
    public function stStoryCheck(): void
    {
        // Starting this point, whe enter the second phase of the game
        $this->setGamePhase(2);
        // be parse memory in reverse order
        static::DbQuery(
            "UPDATE card  SET card_location_arg = - card_location_arg WHERE card_location = 'memory'"
        );
        $memoryFakeTop =  $this->twdDeck->generateFakeCard($this->twdDeck->getCardOnTop("memory"));

        // notify
        $this->notify->all("storyCheckStarted", \clienttranslate("Story check started"), array(
            "memoryTopCard" => $memoryFakeTop
        ));
        // Go to following game state
        $this->gamestate->nextState("");
    }

    /**
     * Game state action, step of story check : apply card effect, ask for player input if needed
     */
    public function stStoryCheckStep(): void
    {
        //TODO remove after tests
        $needPlayerInput = true;

        // Go to following game state
        if ($needPlayerInput)
            $this->gamestate->nextState("playerChoice");
        else
            $this->gamestate->nextState("gameCheck");
    }

    private function checkWin(): bool
    {
        // TODO: implement win condition check
        return false;
    }
    private function checkLoss(): bool
    {
        // TODO: implement loss condition check
        $graveyardNb = $this->twdDeck->countCardInLocation("graveyard");

        return $graveyardNb >= intval($this->getGameStateValue("lossCondition"));
    }

    /**
     * Game state action, check win or loss condition
     */
    public function stStoryCheckGameWinLoss(): void
    {
        $win = $this->checkWin(); // TODO: check win condition
        $loss = $this->checkLoss(); // TODO: check loss condition

        // Go to following game state
        if ($loss) {
            $this->notify->all("gameLoss", \clienttranslate("You lost the game"));
            $this->gamestate->nextState("gameEnd");
        } else if ($win) {
            $this->gamestate->nextState("gameEnd");
        } else {
            // TODO remove after tests
            $this->notify->all("keepPlaying", \clienttranslate("You have picked an action"));
            $this->gamestate->nextState("nextStep");
        }
    }



    /**
     * Migrate database.
     *
     * You don't have to care about this until your game has been published on BGA. Once your game is on BGA, this
     * method is called everytime the system detects a game running with your old database scheme. In this case, if you
     * change your database scheme, you just have to apply the needed changes in order to update the game database and
     * allow the game to continue to run with your new version.
     *
     * @param int $from_version
     * @return void
     */
    public function upgradeTableDb($from_version)
    {
        //       if ($from_version <= 1404301345)
        //       {
        //            // ! important ! Use DBPREFIX_<table_name> for all tables
        //
        //            $sql = "ALTER TABLE DBPREFIX_xxxxxxx ....";
        //            $this->applyDbUpgradeToAllDB( $sql );
        //       }
        //
        //       if ($from_version <= 1405061421)
        //       {
        //            // ! important ! Use DBPREFIX_<table_name> for all tables
        //
        //            $sql = "CREATE TABLE DBPREFIX_xxxxxxx ....";
        //            $this->applyDbUpgradeToAllDB( $sql );
        //       }
    }

    /*
     * Gather all information about current game situation (visible by the current player).
     *
     * The method is called each time the game interface is displayed to a player, i.e.:
     *
     * - when the game starts
     * - when a player refreshes the game page (F5)
     */
    protected function getAllDatas(): array
    {
        $result = [];

        // WARNING: We must only return information visible by the current player.
        //$current_player_id = (int) $this->getCurrentPlayerId();

        // Get information about players.
        // NOTE: you can retrieve some extra field you added for "player" table in `dbmodel.sql` if you need it.
        $result["players"] = $this->getCollectionFromDb(
            "SELECT `player_id` `id`, `player_score` `score` FROM `player`"
        );

        // TODO: Gather all information about current game situation (visible by player $current_player_id).
        // Cards in player hand
        $result['hand'] = $this->twdDeck->getCardsInLocation('hand');

        // Cards played on the table
        $result['protagonistSlot'] = $this->twdDeck->getCardsInLocation('protagonist');
        $gamePhase = $this->getGameStateValue("gamePhase");
        $memoryTop = $this->twdDeck->getCardOnTop('memory');
        $result['memoryTop'] = $gamePhase == 1 ? $memoryTop :  $this->twdDeck->generateFakeCard($memoryTop);
        $result['memoryNb'] = $this->twdDeck->countCardInLocation('memory');
        $result['escaped'] = $this->twdDeck->getCardsInLocation('escaped', null, 'location_arg');
        $result['graveyardNb'] = $this->twdDeck->countCardInLocation('graveyard');
        $result['graveyardTop'] = $this->twdDeck->getCardOnTop('graveyard') ?  $this->twdDeck->generateFakeCard($this->twdDeck->getCardOnTop('graveyard')) : null;
        $result['ruralDeckNb'] = $this->twdDeck->countCardInLocation('deck_rural');
        $result['urbanDeckNb'] = $this->twdDeck->countCardInLocation('deck_urban');

        // Game difficulty and phase
        $result['difficultyLevel'] = $this->getGameStateValue("difficultyLevel");
        $result['gamePhase'] = $this->getGameStateValue("gamePhase");

        return $result;
    }

    /**
     * Returns the game name.
     *
     * IMPORTANT: Please do not modify.
     */
    protected function getGameName()
    {
        return "thewalkingdeck";
    }

    /**
     * This method is called only once, when a new game is launched. In this method, you must setup the game
     *  according to the game rules, so that the game is ready to be played.
     */
    protected function setupNewGame($players, $options = [])
    {
        // Set the colors of the players with HTML color code. The default below is red/green/blue/orange/brown. The
        // number of colors defined here must correspond to the maximum number of players allowed for the gams.
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("('%s', '%s', '%s', '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                $player["player_canal"],
                addslashes($player["player_name"]),
                addslashes($player["player_avatar"]),
            ]);
        }

        // Create players based on generic information.
        //
        // NOTE: You can add extra field on player table in the database (see dbmodel.sql) and initialize
        // additional fields directly here.
        static::DbQuery(
            sprintf(
                "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // Init global values with their initial values.

        // Difficulty level is define by protagonist picked at game start. Default is 1.
        $this->setGameStateInitialValue("difficultyLevel", 1);
        // Game phase : 1 => expedition phase, 2 => story phase
        $this->setGameStateInitialValue("gamePhase", 1);
        //Loss condition : number of buried events (default 5)
        $this->setGameStateInitialValue("lossCondition", 5);

        // Init game statistics.
        //
        // NOTE: statistics used in this file must be defined in your `stats.inc.php` file.

        // Dummy content.
        // $this->initStat("table", "table_teststat1", 0);
        // $this->initStat("player", "player_teststat1", 0);

        // TODO: Setup the initial game situation here.

        // Create the decks.
        $this->twdDeck->createCards();

        // Activate first player once everything has been initialized and ready.
        $this->activeNextPlayer();
    }

    /**
     * This method is called each time it is the turn of a player who has quit the game (= "zombie" player).
     * You can do whatever you want in order to make sure the turn of this player ends appropriately
     * (ex: pass).
     *
     * Important: your zombie code will be called when the player leaves the game. This action is triggered
     * from the main site and propagated to the gameserver from a server, not from a browser.
     * As a consequence, there is no current player associated to this action. In your zombieTurn function,
     * you must _never_ use `getCurrentPlayerId()` or `getCurrentPlayerName()`, otherwise it will fail with a
     * "Not logged" error message.
     *
     * @param array{ type: string, name: string } $state
     * @param int $active_player
     * @return void
     * @throws feException if the zombie mode is not supported at this game state.
     */
    protected function zombieTurn(array $state, int $active_player): void
    {
        $state_name = $state["name"];

        if ($state["type"] === "activeplayer") {
            switch ($state_name) {
                default: {
                        $this->gamestate->nextState("zombiePass");
                        break;
                    }
            }

            return;
        }

        // Make sure player is in a non-blocking status for role turn.
        if ($state["type"] === "multipleactiveplayer") {
            $this->gamestate->setPlayerNonMultiactive($active_player, '');
            return;
        }

        throw new \feException("Zombie mode not supported at this game state: \"{$state_name}\".");
    }
}
