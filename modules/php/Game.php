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

    protected $cards;
    
    private static array $CARD_TYPE;
    private static array $CARD_PROTA;
    private static array $CARD_RURAL;
    private static array $CARD_URBAN;
    private static int $PROTA_COUNT = 4; // number of protagonists
    private static int $RURAL_COUNT = 18; // number of rural cards
    private static int $URBAN_COUNT = 18; // number of urban cards

    public function __construct()
    {
        parent::__construct();

        $this->initGameStateLabels([
            "difficultyLevel" => 10, // example of game option
        ]);

        $this->cards = $this->getNew( "module.common.deck" );
        $this->cards->init( "card" );

        self::$CARD_TYPE = [
            1 => [
                "type_name" => clienttranslate('Protagonist'),
            ],
            2 => [
                "type_name" => clienttranslate('Rural'),
            ],
            3 => [
                "type_name" => clienttranslate('Urban'),
            ],

        ];
        self::$CARD_PROTA = [
            1 => [
                "card_name" => clienttranslate('Aenor'),
            ],
            2 => [
                "card_name" => clienttranslate('Boris'),
            ],
            3 => [
                "card_name" => clienttranslate('Adrien'),
            ],
            4 => [
                "card_name" => clienttranslate('Eleonore'),
            ],
        ];
        self::$CARD_RURAL = [
            1 => [
                "card_name" => clienttranslate('Punk'),
            ],
            2 => [
                "card_name" => clienttranslate('Piege a loup'),
            ],
            3 => [
                "card_name" => clienttranslate('Clown'),
            ],
            4 => [
                "card_name" => clienttranslate('Ellie et Joel'),
            ],
            5 => [
                "card_name" => clienttranslate('Kieren'),
            ],
            6 => [
                "card_name" => clienttranslate('Tallahassee'),
            ],
            7 => [
                "card_name" => clienttranslate('Gretchen'),
            ],
            8 => [
                "card_name" => clienttranslate('Robert'),
            ],
            9 => [
                "card_name" => clienttranslate('Brigade'),
            ],
            10 => [
                "card_name" => clienttranslate('Feu de camp'),
            ],
            11 => [
                "card_name" => clienttranslate('Cheval'),
            ],
            12 => [
                "card_name" => clienttranslate('Camping-Car'),
            ],
            13 => [
                "card_name" => clienttranslate('Cave'),
            ],
            14 => [
                "card_name" => clienttranslate('Peluche'),
            ],
            15 => [
                "card_name" => clienttranslate('Wild Zero'),
            ],
            16 => [
                "card_name" => clienttranslate('Vaudou'),
            ],
            17 => [
                "card_name" => clienttranslate('Cabot'),
            ],
            18 => [
                "card_name" => clienttranslate('Grenade'),
            ],
        ];
        self::$CARD_URBAN = [
            1 => [
                "card_name" => clienttranslate('Musiciens'),
            ],
            2 => [
                "card_name" => clienttranslate('Chef de chantier'),
            ],
            3 => [
                "card_name" => clienttranslate('Glenn'),
            ],
            4 => [
                "card_name" => clienttranslate('Murphy'),
            ],
            5 => [
                "card_name" => clienttranslate('Horde'),
            ],
            6 => [
                "card_name" => clienttranslate('Majordome'),
            ],
            7 => [
                "card_name" => clienttranslate('Conserve'),
            ],
            8 => [
                "card_name" => clienttranslate('Entrepot'),
            ],
            9 => [
                "card_name" => clienttranslate('Alcool medical'),
            ],
            10 => [
                "card_name" => clienttranslate('Carte'),
            ],
            11 => [
                "card_name" => clienttranslate('Domitille'),
            ],
            12 => [
                "card_name" => clienttranslate('La Faucheuse'),
            ],
            13 => [
                "card_name" => clienttranslate('Controlleur'),
            ],
            14 => [
                "card_name" => clienttranslate('Zoey'),
            ],
            15 => [
                "card_name" => clienttranslate('Jill'),
            ],
            16 => [
                "card_name" => clienttranslate('Shaun'),
            ],
            17 => [
                "card_name" => clienttranslate('Cafe Jeux'),
            ],
            18 => [
                "card_name" => clienttranslate('Enseignante'),
            ],
        ];

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

    /**
     * Player action, play a card from hand
     *
     * @throws BgaUserException
     */
    public function actPlayCard(int $card_id): void
    {
        $player_id = $this->getActivePlayerId();
        throw new \BgaUserException($this->_("Not implemented: ") . "$player_id plays $card_id");
    }

    /**
     * Player action, pass turn (only allowed if cards in decks), auto call when hand is empty
     *
     * @throws BgaUserException
     */
    public function actPass(): void
    {
        /* Check board state 
            if both decks empty
            then if hand = 0 then start story check else stay in same state
            else start new turn
        */
        if ($this->cards->countCardInLocation("deck-rural") === 0 && $this->cards->countCardInLocation("deck-urban") === 0) {
            if ($this->cards->countCardInLocation("hand") === 0) {
                // start story check
                $this->gamestate->nextState("storyCheck");
            } else {
                // stay in same state
            }
        } else {
            // start new turn
            $this->gamestate->nextState("nextTurn");
        }
    }

    /**
     * Player action, pick a protagonist. Defines game difficulty.
     *
     * @throws BgaUserException
     */
    public function actPlayProtagonistCard(int $card_id): void
    {
        $player_id = $this->getActivePlayerId();
        $this->cards->moveCard($card_id, "protagonist");
        $card = $this->cards->getCard($card_id);
        $difficulty = intval($card["type_arg"]);
        $cardname = self::$CARD_PROTA[$difficulty]["card_name"];
        $this->setDifficulty($difficulty);
        $this->cards->moveAllCardsInLocation( "hand", "discard");
        $this->notify->all("protagonistCardPlayed", \clienttranslate("Protagonist $cardname played, difficulty set to $difficulty"), array(
            "player_id" => $player_id,
            "card" => $card,
            "difficulty" => $difficulty
        ));
        
        // at the end of the action, move to the next state
        $this->gamestate->nextState("");
    }

    /**
     * Player action : drawing a card from Urban deck
     *
     * @throws BgaUserException
     */
    public function actDrawUrbanCard(int $card_id): void
    {
        
        $this->checkHand();
    }
    /**
     * Player action : drawing a card from Rural deck
     *
     * @throws BgaUserException
     */
    public function actDrawRuralCard(int $card_id): void
    {
        
        $this->checkHand();
    }
    private function checkHand(): void
    {
        if ($this->cards->countCardInLocation("hand") === 3) {
            $this->gamestate->nextState("");
        }
    }

    /**
     * Player action : resolve effects during a story check step
     *
     * @throws BgaUserException
     */
    public function actStoryCheckPlayerChoice(int $card_id): void
    {
        
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
    public function stStoryCheck(): void {
 
        // Go to following game state
        $this->gamestate->nextState("");
    }

    /**
     * Game state action, step of story check : apply card effect, ask for player input if needed
     */
    public function stStoryCheckStep(): void {

        $needPlayerInput = false;

        // Go to following game state
        if ($needPlayerInput)
            $this->gamestate->nextState("playerChoice");
        else
            $this->gamestate->nextState("gameCheck");
    }

    /**
     * Game state action, check win or loss condition
     */
    public function stStoryCheckGameWinLoss(): void {
        $win = false; // TODO: check win condition
        $loss = false; // TODO: check loss condition
        // Go to following game state
        if ($loss) {
            $this->gamestate->nextState("gameEnd");
        } else if ($win) {
            $this->gamestate->nextState("gameEnd");
        } else {
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
        $result['hand'] = $this->cards->getCardsInLocation( 'hand');
        
        // Cards played on the table
        $result['protagonistSlot'] = $this->cards->getCardsInLocation( 'protagonist');
        $result['memoryTop'] = $this->cards->getCardOnTop( 'memory');
        $result['memoryNb'] = $this->cards->countCardInLocation( 'memory');
        $result['escaped'] = $this->cards->getCardsInLocation( 'escaped', null, 'location_arg');
        $result['graveyardNb'] = $this->cards->countCardInLocation( 'graveyard');
        $result['ruralDeckNb'] = $this->cards->countCardInLocation( 'deck-rural');
        $result['urbanDeckNb'] = $this->cards->countCardInLocation( 'deck-urban');

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

        // Init game statistics.
        //
        // NOTE: statistics used in this file must be defined in your `stats.inc.php` file.

        // Dummy content.
        // $this->initStat("table", "table_teststat1", 0);
        // $this->initStat("player", "player_teststat1", 0);

        // TODO: Setup the initial game situation here.

        //create protoganist cards
        $cards = [];
        //$pcards[] = ['type' => ];
        for ($i = 1; $i <= 4; $i++) // 4 protagonists
            $cards[] = ['type' => 1, 'type_arg' => $i, 'nbr' => 1];
        $this->cards->createCards($cards, 'hand');
        $cards = [];
        for ($i = 1; $i <= 18; $i++) // 18 cards in each deck
                $cards[] = ['type' => 2, 'type_arg' => $i, 'nbr' => 1];
        $this->cards->createCards($cards, 'deck-rural');
        $this->cards->shuffle('deck-rural');
        $cards = [];
        for ($i = 1; $i <= 18; $i++) // 18 cards in each deck
                $cards[] = ['type' => 3, 'type_arg' => $i, 'nbr' => 1];
        $this->cards->createCards($cards, 'deck-urban');
        $this->cards->shuffle('deck-urban');

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
                default:
                {
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
