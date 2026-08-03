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

use Bga\Games\TheWalkingDeck\Constants\CardType;
use Bga\Games\TheWalkingDeck\Constants\EventType;
use Bga\Games\TheWalkingDeck\Constants\GameStep;
use Bga\Games\TheWalkingDeck\Constants\Location;
use Bga\Games\TheWalkingDeck\Constants\Rules;
use Bga\Games\TheWalkingDeck\Constants\Transition;
use Bga\GameFramework\NotificationMessage;
use Bga\GameFramework\SystemException;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
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
    protected $eventStack;

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
        ]);

        $this->cards = $this->bga->deckFactory->createDeck('twd_card');
        $this->deckManager = new TWDDeck($this);
        $this->ressources = new TWDRessources($this);
        $this->disaster = $this->bga->deckFactory->createDeck('twd_disaster');
        $this->disasterManager = new TWDDisaster($this);
        $this->eventStack = new TWDEventStack($this);

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

    private function setLossCondition(array $card): int
    {
        // WINLOSS implement loss condition check
        $card_type = intval($card['type']);
        $card_type_arg = intval($card['type_arg']);
        if ($card_type !== 1 || $card_type_arg < 1 || $card_type_arg > 4)
            throw new UserException(new NotificationMessage(
                \clienttranslate('Illegal call to setLossCondition with card ${card_id}'),
                ['card_id' => $card['id'] ?? 'unknown']
            ));
        $lossCon = intval($card['losscon']);
        $this->setGameStateValue('lossCondition', $lossCon);
        return $lossCon;
    }

    private function availableDraws(): int
    {
        return $this->deckManager->countCardInLocation(Location::RURAL)
            + $this->deckManager->countCardInLocation(Location::URBAN);
    }

    private function canContinueNormalDraw(): bool
    {
        return $this->availableDraws() > 0
            && $this->deckManager->countCardInLocation(Location::HAND) < Rules::HAND_SIZE;
    }

    /**
     * Resolve the shared event stack and resume the phase that queued it.
     */
    public function stEventDispatcher(): void
    {
        while (!$this->eventStack->isEmpty()) {
            $event = $this->eventStack->getCurrentEvent();
            if ($event === null) {
                throw new SystemException('Event stack changed unexpectedly');
            }

            switch ($event['type']) {
                case EventType::NEXT_STATE:
                    $transition = $event['parameters']['transition'] ?? null;
                    $this->assertEventTransition($transition);
                    $this->popEvent($event['id']);
                    $this->gamestate->nextState($transition);
                    return;

                case EventType::DRAW_CARD:
                    // Keep this event on the stack for the whole normal refill.
                    if ($this->canContinueNormalDraw()) {
                        $this->gamestate->nextState(Transition::DRAW_CARDS);
                        return;
                    }
                    $this->popEvent($event['id']);
                    break;

                case EventType::SPECIAL_DRAW:
                    $this->popEvent($event['id']);
                    if ($this->availableDraws() > 0) {
                        $this->gamestate->nextState(Transition::ADDITIONAL_DRAW_CARDS);
                        return;
                    }
                    break;

                case EventType::ADDITIONAL_DRAW:
                    $this->popEvent($event['id']);
                    if ($this->availableDraws() > 0) {
                        $this->gamestate->nextState(Transition::ADDITIONAL_DRAW_CARDS);
                        return;
                    }
                    break;

                case EventType::PLAY_CARD:
                    $this->popEvent($event['id']);
                    $this->gamestate->nextState(Transition::PLAY_CARDS);
                    return;

                case EventType::BITE_CHOICE:
                    $this->getPendingBiteChoiceEvent();
                    $this->gamestate->nextState(Transition::BITE_CHOICE);
                    return;

                case EventType::CONSEQUENCE:
                    $cardId = intval($event['parameters']['cardId'] ?? 0);
                    $color = strval($event['parameters']['color'] ?? '');
                    $card = $this->deckManager->getCard($cardId);
                    if (!$card || !in_array($color, ['black', 'white', 'grey'], true)) {
                        throw new SystemException(
                            "Invalid consequence event {$event['id']}"
                        );
                    }

                    $outcome = $this->applyConsequences($card, $color);
                    $this->popEvent($event['id']);

                    if ($outcome['checkLoss'] && $this->isLossReached()) {
                        $this->notify->all(
                            'gameLoss',
                            \clienttranslate('You lost the game')
                        );
                        $this->gamestate->nextState(Transition::GAME_END);
                        return;
                    }

                    if ($outcome['biteChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['brainstorm']
                        ) {
                            throw new SystemException(
                                'A bite choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach (array_reverse($outcome['biteChoices']) as $biteChoice) {
                            $this->eventStack->pushEvent(
                                EventType::BITE_CHOICE,
                                $biteChoice
                            );
                        }
                        break;
                    }

                    if ($outcome['brainstorm']) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                        ) {
                            throw new SystemException(
                                'brainstorm cannot be combined with another deferred consequence'
                            );
                        }

                        if (count($this->getBrainstormAvailableDecks()) > 0) {
                            $this->gamestate->nextState(
                                Transition::BRAINSTORM_DECK_CHOICE
                            );
                            return;
                        }
                    }

                    $hasEscapeChoice = $outcome['escapeTallaChoice']
                        || $outcome['avoidZombieChoice'];
                    if ($hasEscapeChoice) {
                        if ($outcome['additionalDraws'] > 0 || $outcome['startNormalDraw']) {
                            throw new SystemException(
                                'An escape choice cannot be combined with another deferred consequence'
                            );
                        }
                        if ($outcome['escapeTallaChoice'] && $outcome['avoidZombieChoice']) {
                            throw new SystemException(
                                'Multiple escape choices cannot be resolved together'
                            );
                        }

                        $choiceIsAvailable = $outcome['escapeTallaChoice']
                            ? $this->escapeTallaChoiceIsAvailable()
                            : $this->zombieEscapeChoiceIsAvailable();
                        if ($choiceIsAvailable) {
                            $this->gamestate->nextState(
                                $outcome['escapeTallaChoice']
                                    ? Transition::PLAYER_CHOICE
                                    : Transition::AVOID_ZOMBIE_CHOICE
                            );
                            return;
                        }
                    }

                    if ($outcome['additionalDraws'] > 0) {
                        $this->pushAdditionalDrawEvents($outcome['additionalDraws']);
                    }
                    if ($outcome['startNormalDraw']) {
                        $this->eventStack->pushEvent(EventType::DRAW_CARD);
                    }
                    break;

                default:
                    throw new SystemException(
                        "Unhandled event type: {$event['type']}"
                    );
            }
        }

        $defaultTransition = $this->getDefaultEventTransition();
        if ($defaultTransition === Transition::DRAW_CARDS) {
            $this->eventStack->pushEvent(EventType::DRAW_CARD);
        }
        $this->gamestate->nextState($defaultTransition);
        return;
    }

    private function assertEventTransition($transition): void
    {
        $allowedTransitions = [
            Transition::DRAW_CARDS,
            Transition::ADDITIONAL_DRAW_CARDS,
            Transition::PLAY_CARDS,
            Transition::STORY_CHECK,
            Transition::STORY_CHECK_STEP,
            Transition::GAME_END,
        ];

        if (!is_string($transition) || !in_array($transition, $allowedTransitions, true)) {
            throw new SystemException('Invalid event return transition');
        }
    }

    private function popEvent(int $expectedId): void
    {
        $poppedEvent = $this->eventStack->popEvent();
        if ($poppedEvent === null || $poppedEvent['id'] !== $expectedId) {
            throw new SystemException('Unexpected event popped from stack');
        }
    }

    private function pushAdditionalDrawEvents(int $number): void
    {
        if ($number < 1) {
            throw new SystemException('Additional draw count must be positive');
        }
        for ($i = 0; $i < $number; $i++) {
            $this->eventStack->pushEvent(EventType::ADDITIONAL_DRAW);
        }
    }

    private function getDefaultEventTransition(): string
    {
        if (intval($this->getGameStateValue('gamePhase')) === 2) {
            return Transition::STORY_CHECK_STEP;
        }

        $handSize = $this->deckManager->countCardInLocation(Location::HAND);

        if ($this->availableDraws() === 0) {
            return $handSize === 0
                ? Transition::STORY_CHECK
                : Transition::PLAY_CARDS;
        }

        return $handSize === 0
            ? Transition::DRAW_CARDS
            : Transition::PLAY_CARDS;
    }

    private function pushConsequenceEvent(
        int $cardId,
        string $color,
        ?string $returnTransition
    ): void {
        if ($returnTransition !== null) {
            $this->assertEventTransition($returnTransition);
            $this->eventStack->pushEvent(
                EventType::NEXT_STATE,
                ['transition' => $returnTransition]
            );
        }
        $this->eventStack->pushEvent(EventType::CONSEQUENCE, [
            'cardId' => $cardId,
            'color' => $color,
        ]);
    }

    private function withFaceDown(array $card, bool $faceDown): array
    {
        $card['face_down'] = $faceDown;
        return $card;
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
        if ($card && $card['location'] == Location::HAND && ($card['type'] == CardType::RURAL || $card['type'] == CardType::URBAN) && $this->cardCanBePlayedInLocation($card, $location)) { // card can be played
            $this->deckManager->insertCardOnExtremePosition($card_id, $location, true);
            $card = $this->deckManager->getCard($card_id);
            $card_name = $card['card_name'];
            $this->notify->all('cardMoved', \clienttranslate("Card $card_name played from hand to $location"), array(
                'card' => $card,
                'destination' => $location,
                'source' => Location::HAND
            ));

            $consequenceColor = null;
            switch ($location) {
                case Location::MEMORY:
                    $this->notify->all('cardInMemory', \clienttranslate("Card $card_name placed in memory, we will apply white consequences"), array(
                        'card' => $card,
                    ));
                    $consequenceColor = 'white';
                    break;
                case Location::ESCAPED:
                    $this->notify->all('cardInMemory', \clienttranslate("Card $card_name placed in memory, we will apply black consequences"), array(
                        'card' => $card,
                    ));
                    $consequenceColor = 'black';
                    break;
            }

            if ($consequenceColor !== null) {
                $this->pushConsequenceEvent(
                    intval($card['id']),
                    $consequenceColor,
                    null
                );
            }

            $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
        } else {
            throw new UserException(new NotificationMessage(
                \clienttranslate('Illegal move: ${card_name} (${card_id}) cannot be played from hand to location ${location}'),
                [
                    'card_name' => $card_name,
                    'card_id' => $card_id,
                    'location' => $location,
                ]
            ));
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
    private function applyConsequence(array $consequence, array $card): array
    {
        $outcome = [
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => false,
            'brainstorm' => false,
            'biteChoices' => [],
        ];

        switch ($consequence['action']) {
            case 'draw':
                $numCards = intval($consequence['number']);
                if ($numCards < 1) {
                    throw new SystemException('Invalid consequence draw count');
                }
                $outcome['additionalDraws'] = $numCards;
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
                        $this->moveCard(intval($card['id']), Location::GRAVEYARD);
                        $outcome['checkLoss'] = true;
                        break;
                    case 'character':
                        // CONS implement bury character
                        break;
                    case 'topCard':
                        // CONS implement bury top card
                        break;
                    default:
                        throw new UserException(new NotificationMessage(
                            \clienttranslate('Illegal call to bury with ${bury}'),
                            ['bury' => $consequence['bury']]
                        ));
                }
                break;
            case 'bite':
                $bite = intval($consequence['bite'] ?? 0);
                if ($bite < 1 || $bite > 3) {
                    throw new SystemException('Invalid bite value');
                }
                $outcome['biteChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                    'bite' => $bite,
                ];
                break;
            case 'forcePass':
                $outcome['startNormalDraw'] = true;
                break;
            case 'escapeTalla':
                $outcome['escapeTallaChoice'] = true;
                break;
            case 'brainstorm':
                $outcome['brainstorm'] = true;
                break;
            case 'avoid':
                switch ($consequence['avoid'] ?? null) {
                    case 'zombie':
                        $outcome['avoidZombieChoice'] = true;
                        break;
                    case 'zombieordie':
                        if ($this->zombieEscapeChoiceIsAvailable()) {
                            $outcome['avoidZombieChoice'] = true;
                        } else {
                            $this->moveCard(
                                intval($card['id']),
                                Location::GRAVEYARD
                            );
                            $outcome['checkLoss'] = true;
                        }
                        break;
                    default:
                        throw new SystemException(
                            'Invalid avoid consequence target'
                        );
                }
                break;
            case 'none':
            case 'nothing':
                //nothing to do
                break;
            default:
                // Unrecognized action
                $action = $consequence ? $consequence['action'] : 'none';
                $this->notify->all('unmanagedAction', \clienttranslate("Unmanaged action: $action"), array("card" => $card));
        }
        return $outcome;
    }

    /**
     * Parse and execute the array of consequences
     */
    private function applyConsequences(array $card, string $color): array
    {
        $consequence = $card['consequence_' . $color];
        $outcome = [
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => false,
            'brainstorm' => false,
            'biteChoices' => [],
        ];

        if (!$consequence || !isset($consequence['action'])) {
            return $outcome;
        }

        $consequences = [$consequence];
        if ($consequence['action'] === 'multiple') {
            $consequences = [];
            $number = intval($consequence['number'] ?? 0);
            for ($i = 0; $i < $number; $i++) {
                $key = strval($i);
                if (!isset($consequence[$key]) || !is_array($consequence[$key])) {
                    throw new SystemException(
                        "Missing consequence $key for card {$card['id']}"
                    );
                }
                $consequences[] = $consequence[$key];
            }
        }

        foreach ($consequences as $currentConsequence) {
            $action = strval($currentConsequence['action'] ?? 'none');
            $this->notify->all(
                'applyingConsequence',
                \clienttranslate("Applying consequence: $action"),
                ['card' => $card]
            );

            $currentOutcome = $this->applyConsequence($currentConsequence, $card);
            $outcome['additionalDraws'] += $currentOutcome['additionalDraws'];
            $outcome['startNormalDraw'] = $outcome['startNormalDraw'] || $currentOutcome['startNormalDraw'];
            $outcome['checkLoss'] = $outcome['checkLoss'] || $currentOutcome['checkLoss'];
            $outcome['escapeTallaChoice'] = $outcome['escapeTallaChoice'] || $currentOutcome['escapeTallaChoice'];
            $outcome['avoidZombieChoice'] = $outcome['avoidZombieChoice'] || $currentOutcome['avoidZombieChoice'];
            $outcome['brainstorm'] = $outcome['brainstorm'] || $currentOutcome['brainstorm'];
            $outcome['biteChoices'] = array_merge(
                $outcome['biteChoices'],
                $currentOutcome['biteChoices']
            );
        }

        return $outcome;
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
            case Location::CHARACTERS_IN_PLAY:
                return intval($card['is_character'] ?? 0) === 1;
            case Location::MEMORY:
                return $card['consequence_white'] || $card['consequence_grey'];
            case Location::ESCAPED:
                return $card['consequence_black'] && $this->consequenceCanBeResolved($card);
        }
        return true;
    }

    public function argEscapeTallaChoice(): array
    {
        return $this->getZombieEscapeChoiceArgs(true);
    }

    public function argAvoidZombieChoice(): array
    {
        return $this->getZombieEscapeChoiceArgs(false);
    }

    private function getZombieEscapeChoiceArgs(bool $canChooseMemory): array
    {
        $zombies = $this->getZombiesInHand();
        $handIsEmpty = $this->deckManager->getCardsInLocation(
            Location::HAND
        ) === [];
        $memoryIsAvailable = $canChooseMemory
            && $this->deckManager->getCardOnTop(Location::MEMORY) !== null;

        return [
            'playableCardsIds' => array_map(
                'intval',
                array_column($zombies, 'id')
            ),
            'canChooseMemory' => $memoryIsAvailable,
            'mustChooseMemory' => $handIsEmpty && $memoryIsAvailable,
        ];
    }

    private function getZombiesInHand(): array
    {
        return array_values(array_filter(
            $this->deckManager->getCardsInLocation(Location::HAND),
            static function (array $card): bool {
                return intval($card['is_zombie'] ?? 0) === 1;
            }
        ));
    }

    private function escapeTallaChoiceIsAvailable(): bool
    {
        return $this->zombieEscapeChoiceIsAvailable()
            || $this->deckManager->getCardOnTop(Location::MEMORY) !== null;
    }

    private function zombieEscapeChoiceIsAvailable(): bool
    {
        return count($this->getZombiesInHand()) > 0;
    }

    public function argBiteChoice(): array
    {
        $event = $this->getPendingBiteChoiceEvent();
        $characters = array_values(
            $this->deckManager->getCardsInLocation(
                Location::CHARACTERS_IN_PLAY
            )
        );

        return [
            'bite' => intval($event['parameters']['bite']),
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'eligibleCharacters' => $characters,
            'eligibleCardsIds' => array_map(
                'intval',
                array_column($characters, 'id')
            ),
        ];
    }

    /**
     * Record the character selected for a bite consequence.
     * Applying wounds and weaknesses will be implemented with the bite rules.
     */
    public function actChooseBiteTarget(int $card_id): void
    {
        $this->checkAction('actChooseBiteTarget');

        $event = $this->getPendingBiteChoiceEvent();
        $eligibleCharacters = $this->deckManager->getCardsInLocation(
            Location::CHARACTERS_IN_PLAY
        );
        $eligibleCardIds = array_map(
            'intval',
            array_column($eligibleCharacters, 'id')
        );
        if (!in_array($card_id, $eligibleCardIds, true)) {
            throw new UserException(
                \clienttranslate('You must choose a character in play')
            );
        }

        $target = $this->deckManager->getCard($card_id);
        $this->notify->all(
            'biteTargetChosen',
            \clienttranslate('${card_name} was chosen to receive bite ${bite}'),
            [
                'card' => $target,
                'card_name' => $target['card_name'],
                'bite' => intval($event['parameters']['bite']),
                'sourceCardId' => intval(
                    $event['parameters']['sourceCardId']
                ),
            ]
        );

        // The actual wound/weakness mutation belongs here once the rule is set.
        $this->popEvent($event['id']);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingBiteChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if ($event === null || $event['type'] !== EventType::BITE_CHOICE) {
            throw new SystemException('There is no pending bite choice');
        }

        $sourceCardId = intval($event['parameters']['sourceCardId'] ?? 0);
        $bite = intval($event['parameters']['bite'] ?? 0);
        if ($sourceCardId < 1 || $bite < 1 || $bite > 3) {
            throw new SystemException('Invalid pending bite choice');
        }

        return $event;
    }

    /**
     * Resolve an escape consequence by choosing one zombie from hand.
     */
    public function actEscapeZombie(int $card_id): void
    {
        $this->checkAction('actEscapeZombie');

        $zombieCardIds = array_map(
            'intval',
            array_column($this->getZombiesInHand(), 'id')
        );
        if (!in_array($card_id, $zombieCardIds, true)) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('You must choose a zombie from your hand'),
                ['card_id' => $card_id]
            ));
        }

        $this->escapeCardForConsequence(
            $this->deckManager->getCard($card_id),
            Location::HAND,
            \clienttranslate('${card_name} avoided danger and escaped')
        );
    }

    /**
     * Resolve Tallahassee's white consequence with the top card of memory.
     */
    public function actEscapeTallaFromMemory(): void
    {
        $this->checkAction('actEscapeTallaFromMemory');

        $card = $this->deckManager->getCardOnTop(Location::MEMORY);
        if ($card === null) {
            throw new UserException(
                \clienttranslate('There is no card in memory')
            );
        }

        $this->escapeCardForConsequence(
            $card,
            Location::MEMORY,
            \clienttranslate('${card_name} escaped thanks to Tallahassee')
        );
    }

    private function escapeCardForConsequence(
        array $card,
        string $source,
        string $message
    ): void
    {
        $cardId = intval($card['id']);
        $this->deckManager->insertCardOnExtremePosition(
            $cardId,
            Location::ESCAPED,
            true
        );
        $card = $this->deckManager->getCard($cardId);
        $this->notify->all(
            'cardMoved',
            $message,
            [
                'card' => $card,
                'card_name' => $card['card_name'],
                'destination' => Location::ESCAPED,
                'source' => $source,
            ]
        );

        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getBrainstormAvailableDecks(): array
    {
        return array_values(array_filter(
            [Location::RURAL, Location::URBAN],
            function (string $location): bool {
                return $this->deckManager->countCardInLocation($location) > 0;
            }
        ));
    }

    public function argBrainstormDeckChoice(): array
    {
        return [
            'availableDecks' => $this->getBrainstormAvailableDecks(),
        ];
    }

    public function actStartBrainstorm(string $location): void
    {
        $this->checkAction('actStartBrainstorm');

        if (!in_array($location, $this->getBrainstormAvailableDecks(), true)) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('You cannot brainstorm this deck'),
                ['location' => $location]
            ));
        }

        $brainstormLocation = $this->getBrainstormLocationForDeck($location);
        $cards = $this->deckManager->getCardsOnTop(3, $location);
        foreach ($cards as $position => $card) {
            $cardId = intval($card['id']);
            $this->deckManager->moveCard(
                $cardId,
                $brainstormLocation,
                $position
            );
            $card = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is revealed for brainstorm'),
                [
                    'card' => $card,
                    'card_name' => $card['card_name'],
                    'destination' => 'brainstorm',
                    'source' => $location,
                ]
            );
        }

        $this->gamestate->nextState(Transition::BRAINSTORM_REORDER);
    }

    public function argBrainstormReorder(): array
    {
        $brainstormLocation = $this->getCurrentBrainstormLocation();
        if ($brainstormLocation === null) {
            throw new SystemException('No cards are available for brainstorm');
        }

        return [
            'cards' => $this->deckManager->getCardsInLocation(
                $brainstormLocation,
                null,
                'location_arg'
            ),
            'source' => $this->getDeckForBrainstormLocation(
                $brainstormLocation
            ),
        ];
    }

    public function actConfirmBrainstorm(string $card_ids): void
    {
        $this->checkAction('actConfirmBrainstorm');

        try {
            $orderedCardIds = json_decode(
                $card_ids,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new UserException(
                \clienttranslate('Invalid brainstorm card order')
            );
        }
        if (!is_array($orderedCardIds)) {
            throw new UserException(
                \clienttranslate('Invalid brainstorm card order')
            );
        }
        $orderedCardIds = array_map('intval', $orderedCardIds);

        $brainstormLocation = $this->getCurrentBrainstormLocation();
        if ($brainstormLocation === null) {
            throw new SystemException('No brainstorm is in progress');
        }
        $cards = $this->deckManager->getCardsInLocation($brainstormLocation);
        $actualCardIds = array_map('intval', array_column($cards, 'id'));
        $submittedIds = $orderedCardIds;
        sort($actualCardIds);
        sort($submittedIds);
        if (
            count($orderedCardIds) < 1
            || count($orderedCardIds) > 3
            || $actualCardIds !== $submittedIds
        ) {
            throw new UserException(
                \clienttranslate('The brainstorm card order is invalid')
            );
        }

        $source = $this->getDeckForBrainstormLocation($brainstormLocation);
        foreach (array_reverse($orderedCardIds) as $cardId) {
            $this->deckManager->insertCardOnExtremePosition(
                $cardId,
                $source,
                true
            );
            $card = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is returned after brainstorm'),
                [
                    'card' => $card,
                    'card_name' => $card['card_name'],
                    'destination' => $source,
                    'source' => 'brainstorm',
                ]
            );
        }

        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getBrainstormLocationForDeck(string $location): string
    {
        switch ($location) {
            case Location::RURAL:
                return Location::BRAINSTORM_RURAL;
            case Location::URBAN:
                return Location::BRAINSTORM_URBAN;
            default:
                throw new SystemException('Invalid brainstorm source deck');
        }
    }

    private function getDeckForBrainstormLocation(string $location): string
    {
        switch ($location) {
            case Location::BRAINSTORM_RURAL:
                return Location::RURAL;
            case Location::BRAINSTORM_URBAN:
                return Location::URBAN;
            default:
                throw new SystemException('Invalid brainstorm location');
        }
    }

    private function getCurrentBrainstormLocation(): ?string
    {
        $locations = array_values(array_filter(
            [Location::BRAINSTORM_RURAL, Location::BRAINSTORM_URBAN],
            function (string $location): bool {
                return $this->deckManager->countCardInLocation($location) > 0;
            }
        ));
        if (count($locations) > 1) {
            throw new SystemException('Multiple brainstorms are in progress');
        }

        return $locations[0] ?? null;
    }

    /**
     * Player action: start the normal refill when exactly one card remains.
     * An empty hand starts the same refill automatically in stEventDispatcher().
     *
     * @throws BgaUserException
     */
    public function actRefillHand(): void
    {
        $this->checkAction('actRefillHand');

        if ($this->deckManager->countCardInLocation(Location::HAND) !== 1) {
            throw new UserException(\clienttranslate("You can only refill with exactly one card in hand."));
        }
        if ($this->availableDraws() === 0) {
            throw new UserException(\clienttranslate("You can't refill because there are no cards left to draw."));
        }

        $this->eventStack->pushEvent(EventType::DRAW_CARD);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    /**
     * Player action, pick a protagonist. Defines game difficulty.
     *
     * @throws BgaUserException
     */
    public function actPlayProtagonistCard(int $card_id): void
    {
        $card = $this->deckManager->getCard($card_id);
        if (intval($card['type']) == CardType::PROTAGONIST && $card['location'] == Location::HAND && $this->deckManager->countCardInLocation(Location::PROTAGONIST) == 0) {
            $this->deckManager->moveCard($card_id, Location::PROTAGONIST);
            $card = $this->deckManager->getCard($card_id);
            $difficulty = intval($card['type_arg']);
            $this->setDifficulty($difficulty);
            $cardname = $card['card_name'];
            //set loss condition
            $lossCon = $this->setLossCondition($card);
            $this->deckManager->moveAllCardsInLocation(Location::HAND, Location::DISCARD);
            $this->notify->all('protagonistCardPlayed', \clienttranslate("Protagonist $cardname played, loss condition: $lossCon event buried"), array(
                'card' => $card,
                'difficulty' => $difficulty,
                'lossCondition' => $lossCon,
            ));
        } else {
            throw new UserException(new NotificationMessage(
                \clienttranslate('Illegal move: card ${card_id} cannot be played from hand to the protagonist slot'),
                ['card_id' => $card_id]
            ));
        }

        // at the end of the action, move to the next state
        $this->gamestate->nextState(Transition::DEFAULT);
    }

    /**
     * Player action : drawing a card from $location deck
     *
     * @throws BgaUserException
     */
    public function actDrawFromDeck(string $location): void
    {
        $this->checkAction('actDrawFromDeck');

        $isAdditionalDraw = $this->gamestate->getCurrentMainStateId() === GameStep::ADDITIONAL_DRAW;
        $handSize = $this->deckManager->countCardInLocation(Location::HAND);
        if (!$isAdditionalDraw && $handSize >= Rules::HAND_SIZE) {
            throw new UserException(\clienttranslate("You can't draw with a full hand."));
        }

        if ($this->deckManager->countCardInLocation($location) == 0) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('Illegal move: no card left in ${location}'),
                ['location' => $location]
            ));
        }
        // pick the card
        $cardPicked = $this->deckManager->pickCard($location, 0);

        $isSpecialDraw = $cardPicked['special_draw'] == 1;
        if ($isSpecialDraw) {
            $cardId = intval($cardPicked['id']);
            $destination = Location::MEMORY;
            $this->deckManager->insertCardOnExtremePosition($cardId, $destination, true);
            $card = $this->deckManager->getCard($cardId);
            $this->notify->all('cardMoved', \clienttranslate("Special draw triggered by card " . $card['card_name']), array(
                'card' => $card,
                'source' => $location,
                'destination' => $destination,
                'special' => true
            ));
        } else {
            $this->notify->all('cardMoved', \clienttranslate("Card drawn from " . ($location == Location::RURAL ? "Rural" : "Urban") . " deck"), array(
                'card' => $cardPicked,
                'source' => $location,
                'destination' => Location::HAND
            ));
        }

        if ($isSpecialDraw) {
            // Resolve the special draw first, then resume any pending normal refill.
            $this->eventStack->pushEvent(EventType::SPECIAL_DRAW);
        }

        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    /**
     * TODO : remove after tests
     *
     * @throws BgaUserException
     */
    public function actGoToStoryCheck(): void
    {
        $this->checkAction('actGoToStoryCheck');
        if (intval($this->getGameStateValue('gamePhase')) !== 1) {
            throw new UserException(
                \clienttranslate('Story Check has already started')
            );
        }

        $this->gamestate->nextState(Transition::STORY_CHECK);
    }

    /**
     * Player action : resolve effects during a story check step
     *
     * @throws BgaUserException
     */
    public function actStoryCheckPlayerChoice(int $card_id = null): void
    {
        $this->checkAction('actStoryCheckPlayerChoice');

        $currentCard = $this->getCurrentStoryCard();
        if ($currentCard === null) {
            throw new UserException(
                \clienttranslate('There is no current Story Check card')
            );
        }
        if ($card_id !== null && $card_id !== intval($currentCard['id'])) {
            throw new UserException(
                \clienttranslate('This is not the current Story Check card')
            );
        }

        $cardId = intval($currentCard['id']);
        if ($this->getPendingStoryAction($currentCard) === 'applyGreyConsequence') {
            $this->deckManager->moveCard(
                $cardId,
                Location::STORY_CURRENT,
                1
            );
            $this->pushConsequenceEvent(
                $cardId,
                'grey',
                Transition::STORY_CHECK_STEP
            );
            $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
            return;
        }

        if (!$this->cardCanBePlayedInLocation(
            $currentCard,
            Location::CHARACTERS_IN_PLAY
        )) {
            throw new UserException(
                \clienttranslate('This Story Check card does not require confirmation')
            );
        }

        $this->deckManager->moveCard(
            $cardId,
            Location::CHARACTERS_IN_PLAY
        );
        $character = $this->deckManager->getCard($cardId);
        $this->notify->all(
            'characterPutInPlay',
            \clienttranslate('${card_name} joins the characters'),
            [
                'card' => $character,
                'card_name' => $character['card_name'],
                'source' => Location::STORY_CURRENT,
            ]
        );
        $this->gamestate->nextState(Transition::PHASE_2);
    }

    /**
     * Player action : drawing a card from Disaster bag
     *
     * @throws BgaUserException
     */
    public function actDrawFromDisasterBag(): void
    {
        // CONS replace with exception
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
        // SCORE compute and return the game progression

        return 0;
    }

    /**
     * Game state action, start the second phase of the game
     */
    public function stStoryCheck(): void
    {
        $isStartingStoryCheck = intval(
            $this->getGameStateValue('gamePhase')
        ) !== 2;
        if ($isStartingStoryCheck) {
            $this->setGamePhase(2);
            static::DbQuery(
                "UPDATE `twd_card`
                 SET `card_location_arg` = - `card_location_arg`
                 WHERE `card_location` = 'memory'"
            );
        }

        $memoryTop = $this->deckManager->getCardOnTop(Location::MEMORY);
        $memoryVisibleTop = $memoryTop === null
            ? null
            : $this->withFaceDown($memoryTop, true);

        if ($isStartingStoryCheck) {
            $this->notify->all(
                'storyCheckStarted',
                \clienttranslate("Story check started"),
                ['memoryTopCard' => $memoryVisibleTop]
            );
        }
        // Go to following game state
        $this->gamestate->nextState(Transition::DEFAULT);
    }

    /**
     * Game state action, step of story check : apply card effect, ask for player input if needed
     */
    public function stStoryCheckStep(): void
    {
        $currentCard = $this->getCurrentStoryCard();
        if ($currentCard === null) {
            $memoryTop = $this->deckManager->getCardOnTop(Location::MEMORY);
            if ($memoryTop !== null) {
                $cardId = intval($memoryTop['id']);
                $this->deckManager->moveCard(
                    $cardId,
                    Location::STORY_CURRENT
                );
                $currentCard = $this->deckManager->getCard($cardId);
                $nextMemoryTop = $this->deckManager->getCardOnTop(
                    Location::MEMORY
                );
                $this->notify->all(
                    'storyCardRevealed',
                    \clienttranslate('${card_name} is the current Story Check card'),
                    [
                        'card' => $this->withFaceDown($currentCard, false),
                        'card_name' => $currentCard['card_name'],
                        'memoryTopCard' => $nextMemoryTop === null
                            ? null
                            : $this->withFaceDown($nextMemoryTop, true),
                        'memoryNb' => $this->deckManager->countCardInLocation(
                            Location::MEMORY
                        ),
                    ]
                );
                $currentCard = $this->getCurrentStoryCard();
                if ($currentCard === null) {
                    throw new SystemException(
                        'Story Check card could not be installed'
                    );
                }
            }
        }

        if ($currentCard === null) {
            $this->gamestate->nextState('gameCheck');
            return;
        }

        if ($this->getPendingStoryAction($currentCard) !== 'resolveCard') {
            $this->gamestate->nextState('playerChoice');
            return;
        }

        $cardId = intval($currentCard['id']);
        $this->deckManager->moveCard($cardId, Location::DONE);
        $this->notify->all(
            'storyCardResolved',
            \clienttranslate('${card_name} has been resolved'),
            [
                'card' => $currentCard,
                'card_name' => $currentCard['card_name'],
            ]
        );
        $this->gamestate->nextState('gameCheck');
    }

    public function argStoryCheckPlayerChoice(): array
    {
        $currentCard = $this->getCurrentStoryCard();
        return [
            'currentCard' => $currentCard,
            'pendingAction' => $currentCard === null
                ? null
                : $this->getPendingStoryAction($currentCard),
        ];
    }

    private function getPendingStoryAction(array $card): string
    {
        if (
            intval($card['location_arg'] ?? 0) === 0
            && !empty($card['consequence_grey'])
        ) {
            return 'applyGreyConsequence';
        }

        return $this->cardCanBePlayedInLocation(
            $card,
            Location::CHARACTERS_IN_PLAY
        )
            ? 'placeCharacter'
            : 'resolveCard';
    }

    private function getCurrentStoryCard(): ?array
    {
        return $this->deckManager->getCardOnTop(Location::STORY_CURRENT);
    }

    private function checkWin(): bool
    {
        return $this->deckManager->countCardInLocation(Location::MEMORY) === 0
            && $this->getCurrentStoryCard() === null;
    }
    private function isLossReached(): bool
    {
        $graveyardNb = $this->deckManager->countCardInLocation(Location::GRAVEYARD);
        return $graveyardNb >= intval($this->getGameStateValue('lossCondition'));
    }

    private function checkLoss(): void
    {
        if ($this->isLossReached()) {
            $this->notify->all('gameLoss', \clienttranslate("You lost the game"));
            $this->gamestate->nextState(Transition::GAME_END);
        }
    }

    /**
     * Game state action, check win or loss condition
     */
    public function stStoryCheckGameWinLoss(): void
    {
        $win = $this->checkWin(); // WINLOSS check win condition
        $loss = $this->isLossReached(); // WINLOSS check loss condition

        // Go to following game state
        if ($loss) {
            $this->notify->all('gameLoss', \clienttranslate("You lost the game"));
            $this->gamestate->nextState(Transition::GAME_END);
        } else if ($win) {
            $this->notify->all(
                'gameWin',
                \clienttranslate("You won the game")
            );
            $this->gamestate->nextState(Transition::GAME_END);
        } else {
            // TEST remove after tests
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

        // Cards in player hand
        $result['hand'] = $this->deckManager->getCardsInLocation('hand');

        // Cards played on the table
        $result['protagonistSlot'] = $this->deckManager->getCardsInLocation(Location::PROTAGONIST);
        $gamePhase = $this->getGameStateValue('gamePhase');
        $memoryTop = $this->deckManager->getCardOnTop(Location::MEMORY);
        $result['memoryTop'] = $gamePhase == 1 || $memoryTop === null
            ? $memoryTop
            : $this->withFaceDown($memoryTop, true);
        $result['memoryNb'] = $this->deckManager->countCardInLocation(Location::MEMORY);
        $result['storyCurrent'] = $this->getCurrentStoryCard();
        $result['escaped'] = $this->deckManager->getCardsInLocation(Location::ESCAPED, null, 'location_arg');
        $result['graveyardNb'] = $this->deckManager->countCardInLocation(Location::GRAVEYARD);
        $graveyardTop = $this->deckManager->getCardOnTop(Location::GRAVEYARD);
        $result['graveyardTop'] = $graveyardTop ?  $this->deckManager->generateFakeCard($graveyardTop) : null;
        $result['ruralDeckNb'] = $this->deckManager->countCardInLocation(Location::RURAL);
        $result['urbanDeckNb'] = $this->deckManager->countCardInLocation(Location::URBAN);
        $brainstormLocation = $this->getCurrentBrainstormLocation();
        $result['brainstorm'] = $brainstormLocation === null
            ? []
            : $this->deckManager->getCardsInLocation(
                $brainstormLocation,
                null,
                'location_arg'
            );

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
        // Init game statistics.
        //
        // NOTE: statistics used in this file must be defined in your `stats.inc.php` file.

        // Dummy content.
        // $this->initStat("table", "table_teststat1", 0);
        // $this->initStat("player", "player_teststat1", 0);

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

        throw new SystemException("Zombie mode not supported at this game state: \"{$state_name}\".");
    }
}
