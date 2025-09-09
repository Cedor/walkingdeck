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
    protected $disaster;
    protected $deckManager;
    protected $ressources;
    protected $disasterManager;

    public function __construct()
    {
        parent::__construct();

        $this->initGameStateLabels([
            'difficultyLevel' => 10, // example of game option
            'gamePhase' => 11, // 1=> expedition phase, 2=> story phase
            'lossCondition' => 12,
            'ressource1' => 13,
            'ressource2' => 14,
            'ressource3' => 15,
            'additionalDraws' => 16, // additional draws given by cards (default init to 0)
            'additionalDrawsCall' => 17 // 1 return to draw state, 2 return to play state (default init to 0)
        ]);

        $this->cards = $this->getNew("module.common.deck");
        $this->cards->init('twd_card');
        $this->deckManager = new TWDDeck($this);
        $this->ressources = new TWDRessources($this);
        $this->disaster = $this->getNew("module.common.deck");
        $this->disaster->init('twd_disaster');
        $this->disasterManager = new TWDDisaster($this);

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

    public function getCardManager(): \Bga\GameFramework\Components\Deck
    {
        return $this->cards;
    }

    public function getDisaster(): \Bga\GameFramework\Components\Deck
    {
        return $this->disaster;
    }

    private function setDifficulty(int $difficulty): void
    {
        $this->setGameStateValue('difficultyLevel', $difficulty);
    }

    private function setGamePhase(int $phase): void
    {
        $this->setGameStateValue('gamePhase', $phase);
    }

    // TODO rewrite State Stack
    private function getAdditionalDraws(): int
    {
        return intval($this->getGameStateValue('additionalDraws'));
    }

    // TODO rewrite State Stack
    private function setAdditionalDraws(int $nb): void
    {
        $this->setGameStateValue('additionalDraws', $nb < 0 ? 0 : $nb);
        if ($nb < 0) throw new \BgaUserException($this->_("Illegal call to setAdditionalDraws with") . $nb);
    }

    // TODO rewrite State Stack
    private function increaseAdditionalDraws($nb = 1): void
    {
        $this->setAdditionalDraws($this->getAdditionalDraws() + $nb);
    }

    // TODO rewrite State Stack
    private function decreaseAdditionalDraws(): void
    {
        $this->setAdditionalDraws($this->getAdditionalDraws() - 1);
    }

    // TODO rewrite State Stack
    private function getAdditionalDrawsCall(): string
    {
        $val = intval($this->getGameStateValue('additionalDrawsCall'));
        $nextState = TWDTransition\DefaultTransition;
        switch ($val) {
            case 1:
                $nextState = TWDTransition\DrawCards;
                break;
            case 2:
                $nextState = TWDTransition\PlayCards;
                break;
            default:
                $nextState = 'none';
                break;
        }
        return $nextState;
    }

    // TODO rewrite State Stack
    private function setAdditionalDrawsCall(string $nextState): void
    {
        $val = 0;
        switch ($nextState) {
            case TWDTransition\DrawCards:
                $val = 1;
                break;
            case TWDTransition\PlayCards:
                $val = 2;
                break;
            case 'none':
                $val = 0;
                break;
            default:
                throw new \BgaUserException($this->_("Illegal value for additionalDrawsCall: ") . $nextState);
        }

        $this->setGameStateValue('additionalDrawsCall', $val);
    }

    private function setLossCondition(array $card): int
    {
        // TODO: implement loss condition check
        $card_type = intval($card['type']);
        $card_type_arg = intval($card['type_arg']);
        if ($card_type !== 1 || $card_type_arg < 1 || $card_type_arg > 4)
            throw new \BgaUserException($this->_("Illegal call to setLossCondition with $card"));
        $lossCon = intval($card['losscon']);
        $this->setGameStateValue('lossCondition', $lossCon);
        return $lossCon;
    }

    /**
     * Player action, play a card from hand
     *
     * @throws BgaUserException
     */
    public function actPlayCard(int $card_id, string $location): void
    {
        $card = $this->deckManager->getCard($card_id);
        $card_name = '';
        $forcePass = false;
        $nextState = 'pass';
        if ($card && $card['location'] == TWDLocation\Hand && ($card['type'] == TWDCardType\Rural || $card['type'] == TWDCardType\Urban) && $this->cardCanBePlayedInLocation($card, $location)) { // card can be played
            $this->deckManager->insertCardOnExtremePosition($card_id, $location, true);
            $card = $this->deckManager->getCard($card_id);
            $card_name = $card['card_name'];
            $this->notify->all('cardMoved', \clienttranslate("Card $card_name played from hand to $location"), array(
                'card' => $card,
                'destination' => $location,
                'source' => TWDLocation\Hand
            ));
            // now determine next step
            switch ($location) {
                case TWDLocation\Memory:
                    $this->notify->all('cardInMemory', \clienttranslate("Card $card_name placed in memory, we will apply white consequences"), array(
                        'card' => $card,
                    ));
                    $nextState = $this->applyConsequences($card, 'white');
                    break;
                case TWDLocation\Escaped:
                    $nextState = $this->applyConsequences($card, 'black');
            }
            // go to next step
            switch ($nextState) {
                case TWDTransition\AdditionalDrawCards:
                case 'forcePass':
                    $forcePass = true;
                case 'checkLoss':
                    if ($this->isLossReached()) { // if loss is reached, got to end state and break execution
                        $this->notify->all('gameLoss', \clienttranslate("You lost the game"));
                        $this->gamestate->nextState(TWDTransition\GameEnd);
                        break;
                    } // else continue to next turn/action
                case 'pass':
                default:
                    $this->actPass($forcePass);
            }
        } else {
            throw new \BgaUserException($this->_('Illegal Move: ') . "$card_name ($card_id) cannot be played from hand to location $location");
        }
    }

    private function moveCard(int $card_id, string $location, int $location_arg = 0): void
    {
        $source = $this->deckManager->getCard($card_id)['location'];
        $this->deckManager->moveCard($card_id, $location, $location_arg);
        $card = $this->deckManager->getCard($card_id);
        $card_name = $card['card_name'];
        $this->notify->all('cardMoved', \clienttranslate("Card $card_name moved from $source to $location"), array(
            'card' => $card,
            'destination' => $location,
            'source' => $source
        ));
    }

    /**
     * Parse and execute ONE consequence array
     * This is THE function that handles the consequences of card actions
     */
    private function applyConsequence(array $consequence, array $card): string
    {
        $nextState = 'pass';
        switch ($consequence['action']) {
            case 'draw':
                $numCards = intval($consequence['number']);
                $this->increaseAdditionalDraws($numCards);
                $this->setAdditionalDrawsCall(TWDTransition\PlayCards); // TODO rewrite State Stack
                $nextState = TWDTransition\AdditionalDrawCards;
                break;
            case 'consume':
                $this->ressources->consumeRessources($consequence['ressource']);
                break;
            case 'restore':
                $this->ressources->refillRessources($consequence['ressource']);
                break;
            case 'bury':
                switch ($consequence['bury']) {
                    case 'this':
                        $this->moveCard(intval($card['id']), TWDLocation\Graveyard);
                        $nextState = 'checkLoss';
                        break;
                    case 'character':
                        // TODO implement bury character
                        break;
                    case 'topCard':
                        // TODO implement bury top card
                        break;
                    default:
                        throw new \BgaUserException($this->_("Illegal call to bury with ") . $consequence['bury']);
                }
                break;
            case 'bite':
                // TODO implement bite damage (phase 2 only)
                break;
            case 'none':
                //nothing to do
                break;
            default:
                // Unrecognized action
                $action = $consequence ? $consequence['action'] : 'none';
                $this->notify->all('unmanagedAction', \clienttranslate("Unmanaged action: $action"), array("card" => $card));
        }
        return $nextState;
    }
    /**
     * Parse and execute the array of consequences
     */
    private function applyConsequences(array $card, string $color): string
    {
        // Apply the consequence of the card based on its color
        $consequence = $card['consequence_' . $color];
        $nextState = 'pass';
        if ($consequence && $consequence['action']) {
            if ($consequence['action'] == 'multiple' && isset($consequence['number'])) {
                // TODO remove after implementation
                // temporary disable multiple actions
                $this->notify->all('unmanagedAction', \clienttranslate("Multiple actions not yet implemented, skipping all actions"), array('card' => $card));
                return 'pass';
                for ($i = 0; $i < intval($consequence['number']); $i++) {
                    if (isset($consequence[strval($i)])) {
                        $nextState = $this->applyConsequence($consequence[strval($i)], $card);
                        if ($nextState == 'additionalDraws') {
                            // we have to stop here, we will continue later
                            break;
                        }
                    }
                }
            } else {
                $action = $consequence ? $consequence['action'] : 'none';
                $this->notify->all('applyingConsequence', \clienttranslate("Applying consequence: $action"), array('card' => $card));
                $nextState = $this->applyConsequence($consequence, $card);
                $this->notify->all('appliedConsequence', \clienttranslate("Going for next state: $nextState"), array('card' => $card));
            }
        } else {
            $action = $consequence ? $consequence['action'] : 'none';
            $this->notify->all('unmanagedAction', \clienttranslate("Unmanaged action: $action"), array('card' => $card));
        }
        return $nextState;
    }

    private function consequenceCanBeResolved(array $card): bool
    {
        // Check if the consequence of the card can be resolved
        // For example, you might want to check if the player has enough resources to resolve the consequence
        // Here we assume that all consequences can be resolved for simplicity
        return true;
    }

    private function cardCanBePlayedInLocation(array $card, string $location): bool
    {
        switch ($location) {
            case TWDLocation\CharactersInPlay:
                return $card['is_character'] == '1';
                break;
            case TWDLocation\Memory:
                return $card['consequence_white'] || $card['consequence_grey'];
                break;
            case TWDLocation\Escaped:
                return $card['consequence_black'] && $this->consequenceCanBeResolved($card);
                break;
        }
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
        if ($force && $this->deckManager->countCardInLocation(TWDLocation\Hand) > 2 && $this->getAdditionalDraws() == 0)
            throw new \BgaUserException($this->_("You can't pass, play some cards first."));
        if ($this->deckManager->countCardInLocation(TWDLocation\Rural) == 0 && $this->deckManager->countCardInLocation(TWDLocation\Urban) == 0) {
            if ($this->deckManager->countCardInLocation(TWDLocation\Hand) == 0)
                // start story check
                $this->gamestate->nextState(TWDTransition\StoryCheck);
            else {
                // keep playing (even with additional draws)
                $this->gamestate->nextState(TWDTransition\PlayCards);
            }
        } else {
            if ($this->deckManager->countCardInLocation(TWDLocation\Hand) == 0 || $force) {
                // go draw again
                if ($this->getAdditionalDraws() > 0) {
                    // we have additional draws to do
                    $this->gamestate->nextState(TWDTransition\AdditionalDrawCards);
                } else {
                    $this->gamestate->nextState(TWDTransition\DrawCards);
                }
            } else {
                // stay in same state
                $this->gamestate->nextState(TWDTransition\PlayCards);
            }
        }
    }

    /**
     * Player action, pick a protagonist. Defines game difficulty.
     *
     * @throws BgaUserException
     */
    public function actPlayProtagonistCard(int $card_id): void
    {
        $card = $this->deckManager->getCard($card_id);
        if (intval($card['type']) == TWDCardType\Protagonist && $card['location'] == TWDLocation\Hand && $this->deckManager->countCardInLocation(TWDLocation\Protagonist) == 0) {
            $this->deckManager->moveCard($card_id, TWDLocation\Protagonist);
            $card = $this->deckManager->getCard($card_id);
            $difficulty = intval($card['type_arg']);
            $this->setDifficulty($difficulty);
            $cardname = $card['card_name'];
            //set loss condition
            $lossCon = $this->setLossCondition($card);
            $this->deckManager->moveAllCardsInLocation(TWDLocation\Hand, TWDLocation\Discard);
            $this->notify->all('protagonistCardPlayed', \clienttranslate("Protagonist $cardname played, loss condition: $lossCon event buried"), array(
                'card' => $card,
                'difficulty' => $difficulty,
                'lossCondition' => $lossCon,
            ));
        } else throw new \BgaUserException($this->_("Illegal Move: you plays $card_id from Hand to protagonist slot"));

        // at the end of the action, move to the next state
        $this->gamestate->nextState(TWDTransition\DefaultTransition);
    }

    /**
     * Player action : drawing a card from $location deck
     *
     * @throws BgaUserException
     */
    public function actDrawFromDeck(string $location): void
    {
        if ($this->deckManager->countCardInLocation($location) == 0) {
            throw new \BgaUserException($this->_("Illegal Move: No card left in $location"));
        }
        // pick the card
        $cardPicked = $this->deckManager->pickCard($location, 0);

        $additionalDraws = ($this->getAdditionalDraws() > 0);
        if ($additionalDraws) {
            //we are in the additional draw state
            $this->decreaseAdditionalDraws();
        }
        if ($cardPicked['special_draw'] == 1) { // TODO rewrite State Stack
            $this->increaseAdditionalDraws();
            if (!$additionalDraws) { //we were in a regular draw, we need to save current state
                $this->setAdditionalDrawsCall(TWDTransition\DrawCards);
            }
            $cardId = intval($cardPicked['id']);
            $destination = TWDLocation\Memory;
            $this->deckManager->insertCardOnExtremePosition($cardId, $destination, true);
            $card = $this->deckManager->getCard($cardId);
            $this->notify->all('cardMoved', \clienttranslate("Special draw triggered by card " . $card['card_name']), array(
                'card' => $card,
                'source' => $location,
                'destination' => $destination,
                'special' => true
            ));
        } else {
            $this->notify->all('cardMoved', \clienttranslate("Card drawn from " . ($location == TWDLocation\Rural ? "Rural" : "Urban") . " deck"), array(
                'card' => $cardPicked,
                'source' => $location,
                'destination' => TWDLocation\Hand
            ));
        }
        $this->checkHand($additionalDraws);
    }

    private function checkHand(bool $additionalDraws = false): void
    {
        if ($this->deckManager->countCardInLocation(TWDLocation\Rural) == 0 && $this->deckManager->countCardInLocation(TWDLocation\Urban) == 0) {
            // no matter which state we're in, there is no card left to draw
            if ($this->deckManager->countCardInLocation(TWDLocation\Hand) == 0) {
                // no card in hand, go to story check (very rare situation)
                $this->gamestate->nextState(TWDTransition\StoryCheck);
            } else {
                $this->gamestate->nextState(TWDTransition\PlayCards);
            }
        } else if ($additionalDraws) { // TODO rewrite State Stack
            // we come from an additional draw state
            $nextState = $this->getAdditionalDrawsCall();
            if ($this->getAdditionalDraws() > 0) {
                // we still have additionals draws
                $this->gamestate->nextState(TWDTransition\AdditionalDrawCards);
                //else return to previous situation
            } else if ($nextState == TWDTransition\PlayCards || $this->deckManager->countCardInLocation(TWDLocation\Hand) >= 3) { // TODO rewrite State Stack
                $this->setAdditionalDrawsCall('none');
                $this->gamestate->nextState(TWDTransition\PlayCards);
            } else { // TODO rewrite State Stack
                $this->setAdditionalDrawsCall('none');
                $this->gamestate->nextState(TWDTransition\DrawCards);
            }
        } else {
            // we are in regular draw situation
            if ($this->deckManager->countCardInLocation(TWDLocation\Hand) >= 3) {
                $this->gamestate->nextState(TWDTransition\PlayCards);
            } else {
                $this->gamestate->nextState(TWDTransition\DrawCards);
            }
        }
    }

    /**
     * TODO : remove after tests
     *
     * @throws BgaUserException
     */
    public function actGoToStoryCheck(): void
    {

        $this->gamestate->nextState(TWDTransition\StoryCheck);
    }

    /**
     * Player action : resolve effects during a story check step
     *
     * @throws BgaUserException
     */
    public function actStoryCheckPlayerChoice(int $card_id = null): void
    {
        // TODO remove after tests
        $this->notify->all('actionPicked', \clienttranslate("You have picked an action"), array());
        $this->gamestate->nextState(TWDTransition\DefaultTransition);
    }

    /**
     * Player action : drawing a card from Disaster bag
     *
     * @throws BgaUserException
     */
    public function actDrawFromDisasterBag(): void
    {
        // TODO replace with exception
        $shuffle = false;
        if ($this->disasterManager->countCardInLocation('hand') != 0) {
            $this->disasterManager->moveAllCardsInLocation('hand', 'deck');
            $this->disasterManager->shuffle('deck');
            $shuffle = true;
        }
        $disasterPicked = $this->disasterManager->pickCard('deck', 0);
        $this->notify->all('disasterDrawnFromBag', \clienttranslate("Disaster drawn from bag"), array(
            'disaster' => $disasterPicked,
            'shuffle' => $shuffle
        ));
    }

    /**
     * Player action : putting a character in play
     *
     * @throws BgaUserException
     */
    public function actPutCharacterInPlay(int $card_id, string $location): void
    {
        $card = $this->deckManager->getCard($card_id);
        $card_name = '';
        if ($card && $card['location'] == TWDLocation\Hand &&  $this->cardCanBePlayedInLocation($card, $location)) {
            $this->deckManager->moveCard($card_id, 'characters');
            $card = $this->deckManager->getCard($card_id);
            $card_name = $card['card_name'];
            $this->notify->all('characterPutInPlay', \clienttranslate("Card $card_name played from hand to $location"), array(
                'card' => $card,
                'location' => $location
            ));
        } else throw new \BgaUserException($this->_("Illegal Move: Trying to play card $card_id ") . $card["is_character"]);
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
            'playableCardsIds' => [1, 2],
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
        $memoryFakeTop =  $this->deckManager->generateFakeCard($this->deckManager->getCardOnTop(TWDLocation\Memory));

        // notify
        $this->notify->all('storyCheckStarted', \clienttranslate("Story check started"), array(
            'memoryTopCard' => $memoryFakeTop
        ));
        // Go to following game state
        $this->gamestate->nextState(TWDTransition\DefaultTransition);
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
            $this->gamestate->nextState('playerChoice');
        else
            $this->gamestate->nextState('gameCheck');
    }

    private function checkWin(): bool
    {
        // TODO: implement win condition check
        return false;
    }
    private function isLossReached(): bool
    {
        $graveyardNb = $this->deckManager->countCardInLocation(TWDLocation\Graveyard);
        return $graveyardNb >= intval($this->getGameStateValue('lossCondition'));
    }

    private function checkLoss(): void
    {
        if ($this->isLossReached()) {
            $this->notify->all('gameLoss', \clienttranslate("You lost the game"));
            $this->gamestate->nextState(TWDTransition\GameEnd);
        }
    }

    /**
     * Game state action, check win or loss condition
     */
    public function stStoryCheckGameWinLoss(): void
    {
        $win = $this->checkWin(); // TODO: check win condition
        $loss = $this->isLossReached(); // TODO: check loss condition

        // Go to following game state
        if ($loss) {
            $this->notify->all('gameLoss', \clienttranslate("You lost the game"));
            $this->gamestate->nextState(TWDTransition\GameEnd);
        } else if ($win) {
            $this->gamestate->nextState(TWDTransition\GameEnd);
        } else {
            // TODO remove after tests
            $this->notify->all('keepPlaying', \clienttranslate("You have picked an action"));
            $this->gamestate->nextState('nextStep');
        }
    }

    /**
     * Game state action, flip a resource
     */
    public function actFlipRessource(string $token_id): void
    {
        $state = $this->ressources->getRessourceState($token_id);
        if ($state == 1)
            $this->ressources->refillRessources($token_id);
        else $this->ressources->consumeRessources($token_id);
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
        $result['hand'] = $this->deckManager->getCardsInLocation('hand');

        // Cards played on the table
        $result['protagonistSlot'] = $this->deckManager->getCardsInLocation(TWDLocation\Protagonist);
        $gamePhase = $this->getGameStateValue('gamePhase');
        $memoryTop = $this->deckManager->getCardOnTop(TWDLocation\Memory);
        $result['memoryTop'] = $gamePhase == 1 ? $memoryTop :  $this->deckManager->generateFakeCard($memoryTop);
        $result['memoryNb'] = $this->deckManager->countCardInLocation(TWDLocation\Memory);
        $result['escaped'] = $this->deckManager->getCardsInLocation(TWDLocation\Escaped, null, 'location_arg');
        $result['graveyardNb'] = $this->deckManager->countCardInLocation(TWDLocation\Graveyard);
        $graveyardTop = $this->deckManager->getCardOnTop(TWDLocation\Graveyard);
        $result['graveyardTop'] = $graveyardTop ?  $this->deckManager->generateFakeCard($graveyardTop) : null;
        $result['ruralDeckNb'] = $this->deckManager->countCardInLocation(TWDLocation\Rural);
        $result['urbanDeckNb'] = $this->deckManager->countCardInLocation(TWDLocation\Urban);

        // ressources
        $result['ressources'] = $this->ressources->getRessources();

        // Disasters
        $result['disastersReserve'] = $this->disasterManager->getCardsInLocation('reserve');
        $result['disastersDrawn'] = $this->disasterManager->getCardsInLocation('hand');

        // Game difficulty and phase
        $result['difficultyLevel'] = $this->getGameStateValue('difficultyLevel');
        $result['gamePhase'] = $this->getGameStateValue('gamePhase');

        // Characters in play
        $result['charactersInPlay'] = $this->deckManager->getCardsInLocation('characters');

        return $result;
    }

    /**
     * Returns the game name.
     *
     * IMPORTANT: Please do not modify.
     */
    protected function getGameName()
    {
        return 'thewalkingdeck';
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
                $player['player_canal'],
                addslashes($player['player_name']),
                addslashes($player['player_avatar']),
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

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        // Init global values with their initial values.

        // Difficulty level is define by protagonist picked at game start. Default is 1.
        $this->setGameStateInitialValue('difficultyLevel', 1);
        // Game phase : 1 => expedition phase, 2 => story phase
        $this->setGameStateInitialValue('gamePhase', 1);
        // Loss condition : number of buried events (default 5)
        $this->setGameStateInitialValue('lossCondition', 5);
        // additional draws given by cards
        $this->setGameStateInitialValue('additionalDraws', 0);
        $this->setGameStateInitialValue('additionalDrawsCall', 0);

        // Init game statistics.
        //
        // NOTE: statistics used in this file must be defined in your `stats.inc.php` file.

        // Dummy content.
        // $this->initStat("table", "table_teststat1", 0);
        // $this->initStat("player", "player_teststat1", 0);

        // TODO: Setup the initial game situation here.

        // Create the decks.
        $this->deckManager->createCards();

        // Init ressources
        $this->ressources->initRessources();

        // Init disaster
        $this->disasterManager->initDisaster();

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
