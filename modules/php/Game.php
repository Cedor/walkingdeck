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
    private const WIN_SCORE = 20;
    private const DISASTER_CHARACTERISTICS = ['hunger', 'break', 'stress'];
    private const ROBERT_CARD_NAME = 'Robert';
    private const REMOVED_DISASTER_LOCATION = 'removed';
    private const REVEALED_DECK_CARD_STATES = [
        Location::RURAL => 'revealedRuralCardId',
        Location::URBAN => 'revealedUrbanCardId',
    ];

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
            'ressource_hunger' => 13,
            'ressource_break' => 14,
            'ressource_stress' => 15,
            'revealedRuralCardId' => 16,
            'revealedUrbanCardId' => 17,
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

    /**
     * Return the selected protagonist card, or null before selection.
     *
     * Protagonist-specific rules should use this method instead of reading the
     * deck location directly.
     */
    private function getProtagonistInPlay(): ?array
    {
        return $this->deckManager->getCardOnTop(Location::PROTAGONIST);
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

                case EventType::HEAL_CHOICE:
                    $this->getPendingHealChoiceEvent();
                    if (!$this->hasCharactersInPlay()) {
                        $this->popEvent($event['id']);
                        break;
                    }
                    $this->gamestate->nextState(Transition::HEAL_CHOICE);
                    return;

                case EventType::BURY_CHARACTER_CHOICE:
                    $buryEvent = $this->getPendingBuryCharacterChoiceEvent();
                    if ($this->getCharactersInHand() === []) {
                        $this->popEvent($buryEvent['id']);
                        $this->moveCard(
                            intval($buryEvent['parameters']['sourceCardId']),
                            Location::GRAVEYARD
                        );
                        if ($this->isLossReached()) {
                            $this->notify->all(
                                'gameLoss',
                                \clienttranslate('You lost the game')
                            );
                            $this->gamestate->nextState(Transition::GAME_END);
                            return;
                        }
                        break;
                    }
                    $this->gamestate->nextState(Transition::BURY_CHARACTER_CHOICE);
                    return;

                case EventType::BURY_TOP_CARD_CHOICE:
                    $buryEvent = $this->getPendingBuryTopCardChoiceEvent();
                    $availableDecks = $this->getAvailableDrawDecks();
                    if (count($availableDecks) < 2) {
                        $this->popEvent($buryEvent['id']);
                        if ($availableDecks === []) {
                            $this->moveCard(
                                intval($buryEvent['parameters']['sourceCardId']),
                                Location::GRAVEYARD
                            );
                        } else {
                            $this->buryTopCardOfDeck($availableDecks[0]);
                        }
                        if ($this->isLossReached()) {
                            $this->notify->all(
                                'gameLoss',
                                \clienttranslate('You lost the game')
                            );
                            $this->gamestate->nextState(Transition::GAME_END);
                            return;
                        }
                        break;
                    }
                    $this->gamestate->nextState(Transition::BURY_TOP_CARD_CHOICE);
                    return;

                case EventType::DRAFT_DISASTER_CHOICE:
                    $draftEvent = $this->getPendingDraftDisasterChoiceEvent();
                    if (
                        $this->disasterManager->countCardInLocation('deck') === 0
                        && $this->disasterManager->countCardInLocation('hand') === 0
                    ) {
                        $this->popEvent($draftEvent['id']);
                        break;
                    }
                    $this->gamestate->nextState(Transition::DRAFT_DISASTER_CHOICE);
                    return;

                case EventType::AVOID_DECK_CHOICE:
                    $avoidEvent = $this->getPendingAvoidDeckChoiceEvent();
                    $availableDecks = $this->getAvailableDrawDecks();
                    if (count($availableDecks) < 2) {
                        $this->popEvent($avoidEvent['id']);
                        if ($availableDecks !== []) {
                            $this->escapeTopCardsOfDeck($availableDecks[0], 2);
                        }
                        break;
                    }
                    $this->gamestate->nextState(Transition::AVOID_DECK_CHOICE);
                    return;

                case EventType::DISASTER_CHOICE:
                    $this->getPendingDisasterChoiceEvent();
                    if (!$this->hasCharactersInPlay()) {
                        $this->popEvent($event['id']);
                        break;
                    }
                    $this->gamestate->nextState(Transition::DISASTER_CHOICE);
                    return;

                case EventType::WOLF_TRAP_CHOICE:
                    $this->getPendingWolfTrapChoiceEvent();
                    $this->gamestate->nextState(Transition::WOLF_TRAP_CHOICE);
                    return;

                case EventType::RECOVER_CHOICE:
                    $this->getPendingRecoverChoiceEvent();
                    if ($this->deckManager->countCardInLocation(Location::ESCAPED) === 0) {
                        $this->popEvent($event['id']);
                        break;
                    }
                    $this->gamestate->nextState(Transition::RECOVER_CHOICE);
                    return;

                case EventType::UNREMEMBER_CHOICE:
                    $this->getPendingUnrememberChoiceEvent();
                    if (
                        $this->deckManager->countCardInLocation(
                            Location::UNREMEMBER
                        ) === 0
                    ) {
                        if (
                            $this->deckManager->countCardInLocation(
                                Location::MEMORY
                            ) === 0
                        ) {
                            $this->popEvent($event['id']);
                            break;
                        }
                        $this->startUnrememberChoice();
                    }
                    $this->gamestate->nextState(
                        Transition::UNREMEMBER_CHOICE
                    );
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

                    $hasConsequenceOverride = array_key_exists(
                        'consequence',
                        $event['parameters']
                    );
                    $consequence = $hasConsequenceOverride
                        ? $event['parameters']['consequence']
                        : ($card['consequence_' . $color] ?? null);
                    if (
                        $hasConsequenceOverride
                        && !is_array($consequence)
                    ) {
                        throw new SystemException(
                            "Invalid consequence event {$event['id']}"
                        );
                    }

                    if (
                        is_array($consequence)
                        && ($consequence['action'] ?? null) === 'multiple'
                    ) {
                        $number = intval($consequence['number'] ?? 0);
                        $subConsequences = [];
                        for ($i = 0; $i < $number; $i++) {
                            $key = strval($i);
                            if (
                                !isset($consequence[$key])
                                || !is_array($consequence[$key])
                            ) {
                                throw new SystemException(
                                    "Missing consequence $key for card {$card['id']}"
                                );
                            }
                            $subConsequences[] = $consequence[$key];
                        }

                        $this->popEvent($event['id']);
                        for ($i = count($subConsequences) - 1; $i >= 0; $i--) {
                            $this->eventStack->pushEvent(
                                EventType::CONSEQUENCE,
                                [
                                    'cardId' => $cardId,
                                    'color' => $color,
                                    'consequence' => $subConsequences[$i],
                                ]
                            );
                        }
                        break;
                    }

                    $outcome = $this->applyConsequences(
                        $card,
                        $color,
                        is_array($consequence) ? $consequence : null
                    );
                    $this->popEvent($event['id']);

                    if ($outcome['checkLoss'] && $this->isLossReached()) {
                        $this->notify->all(
                            'gameLoss',
                            \clienttranslate('You lost the game')
                        );
                        $this->gamestate->nextState(Transition::GAME_END);
                        return;
                    }

                    if ($outcome['avoidDeckChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['buryCharacterChoices'] !== []
                            || $outcome['buryTopCardChoices'] !== []
                            || $outcome['draftDisasterChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'An avoid deck choice cannot be combined with another deferred consequence'
                            );
                        }
                        foreach ($outcome['avoidDeckChoices'] as $avoidChoice) {
                            $this->eventStack->pushEvent(
                                EventType::AVOID_DECK_CHOICE,
                                $avoidChoice
                            );
                        }
                        break;
                    }

                    if ($outcome['draftDisasterChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['buryCharacterChoices'] !== []
                            || $outcome['buryTopCardChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A draft disaster choice cannot be combined with another deferred consequence'
                            );
                        }
                        foreach ($outcome['draftDisasterChoices'] as $draftChoice) {
                            $this->eventStack->pushEvent(
                                EventType::DRAFT_DISASTER_CHOICE,
                                $draftChoice
                            );
                        }
                        break;
                    }

                    if ($outcome['buryCharacterChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['buryTopCardChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A bury character choice cannot be combined with another deferred consequence'
                            );
                        }
                        foreach ($outcome['buryCharacterChoices'] as $buryChoice) {
                            $this->eventStack->pushEvent(
                                EventType::BURY_CHARACTER_CHOICE,
                                $buryChoice
                            );
                        }
                        break;
                    }

                    if ($outcome['buryTopCardChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['buryCharacterChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A bury top card choice cannot be combined with another deferred consequence'
                            );
                        }
                        foreach ($outcome['buryTopCardChoices'] as $buryChoice) {
                            $this->eventStack->pushEvent(
                                EventType::BURY_TOP_CARD_CHOICE,
                                $buryChoice
                            );
                        }
                        break;
                    }

                    if (
                        $outcome['biteChoices'] !== []
                        && $this->getAvailableCharacterWoundCapacity() > 0
                    ) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                            || $outcome['healChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A bite choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach ($outcome['biteChoices'] as $biteChoice) {
                            $this->eventStack->pushEvent(
                                EventType::BITE_CHOICE,
                                $biteChoice
                            );
                        }
                        break;
                    }

                    if (
                        $outcome['healChoices'] !== []
                        && $this->hasCharactersInPlay()
                    ) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A heal choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach ($outcome['healChoices'] as $healChoice) {
                            $this->eventStack->pushEvent(
                                EventType::HEAL_CHOICE,
                                $healChoice
                            );
                        }
                        break;
                    }

                    if (
                        $outcome['disasterChoices'] !== []
                        && $this->hasCharactersInPlay()
                    ) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['healChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A disaster choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach ($outcome['disasterChoices'] as $disasterChoice) {
                            $this->eventStack->pushEvent(
                                EventType::DISASTER_CHOICE,
                                $disasterChoice
                            );
                        }
                        break;
                    }

                    if ($outcome['wolfTrapChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A Wolf Trap choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach ($outcome['wolfTrapChoices'] as $wolfTrapChoice) {
                            $this->eventStack->pushEvent(
                                EventType::WOLF_TRAP_CHOICE,
                                $wolfTrapChoice
                            );
                        }

                        $hasEscapeChoice = $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice'];
                        if ($hasEscapeChoice) {
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
                        break;
                    }

                    if ($outcome['recoverChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                        ) {
                            throw new SystemException(
                                'A recover choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach ($outcome['recoverChoices'] as $recoverChoice) {
                            $this->eventStack->pushEvent(
                                EventType::RECOVER_CHOICE,
                                $recoverChoice
                            );
                        }
                        break;
                    }

                    if ($outcome['unrememberChoices'] !== []) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'An unremember choice cannot be combined with another deferred consequence'
                            );
                        }

                        foreach (
                            $outcome['unrememberChoices'] as $unrememberChoice
                        ) {
                            $this->eventStack->pushEvent(
                                EventType::UNREMEMBER_CHOICE,
                                $unrememberChoice
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
                            || $outcome['avoidHandChoice']
                            || $outcome['fastMemorise']
                        ) {
                            throw new SystemException(
                                'brainstorm cannot be combined with another deferred consequence'
                            );
                        }

                        if (count($this->getAvailableDrawDecks()) > 0) {
                            $this->gamestate->nextState(
                                Transition::BRAINSTORM_DECK_CHOICE
                            );
                            return;
                        }
                    }

                    if ($outcome['fastMemorise']) {
                        if (
                            $outcome['additionalDraws'] > 0
                            || $outcome['startNormalDraw']
                            || $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['avoidHandChoice']
                            || $outcome['brainstorm']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'fastmemorise cannot be combined with another deferred consequence'
                            );
                        }

                        if (
                            $outcome['fastMemorise'] === Location::HAND
                            && $this->deckManager->countCardInLocation(Location::HAND) > 0
                        ) {
                            $this->gamestate->nextState(
                                Transition::FAST_MEMORISE_HAND_CHOICE
                            );
                            return;
                        }

                        if (
                            $outcome['fastMemorise'] === 'deck'
                            && count($this->getAvailableDrawDecks()) > 0
                        ) {
                            $this->gamestate->nextState(
                                Transition::FAST_MEMORISE_DECK_CHOICE
                            );
                            return;
                        }
                    }

                    if ($outcome['avoidHandChoice']) {
                        if (
                            $outcome['escapeTallaChoice']
                            || $outcome['avoidZombieChoice']
                            || $outcome['brainstorm']
                            || $outcome['fastMemorise']
                            || $outcome['biteChoices'] !== []
                            || $outcome['healChoices'] !== []
                            || $outcome['disasterChoices'] !== []
                            || $outcome['wolfTrapChoices'] !== []
                            || $outcome['recoverChoices'] !== []
                        ) {
                            throw new SystemException(
                                'avoid hand2 cannot be combined with another deferred consequence'
                            );
                        }

                        if ($this->deckManager->countCardInLocation(Location::HAND) > 0) {
                            if ($outcome['additionalDraws'] > 0) {
                                $this->pushAdditionalDrawEvents($outcome['additionalDraws']);
                            }
                            if ($outcome['startNormalDraw']) {
                                $this->eventStack->pushEvent(EventType::DRAW_CARD);
                            }
                            $this->gamestate->nextState(
                                Transition::AVOID_HAND_CHOICE
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

    private function revealTopDeckCard(string $location): void
    {
        $state = self::REVEALED_DECK_CARD_STATES[$location] ?? null;
        if ($state === null) {
            throw new SystemException('Invalid deck reveal location');
        }

        $card = $this->deckManager->getCardOnTop($location);
        $cardId = $card === null ? 0 : intval($card['id']);
        $this->setGameStateValue($state, $cardId);
        if ($card === null) {
            return;
        }

        $this->notify->all(
            'deckTopRevealed',
            \clienttranslate('${card_name} is revealed on top of its deck'),
            [
                'card' => $this->withFaceDown($card, false),
                'card_name' => $card['card_name'],
                'location' => $location,
                'cardNumber' => $this->deckManager->countCardInLocation(
                    $location
                ),
            ]
        );
    }

    private function getRevealedDeckTop(string $location): ?array
    {
        $state = self::REVEALED_DECK_CARD_STATES[$location] ?? null;
        if ($state === null) {
            throw new SystemException('Invalid revealed deck location');
        }

        $topCard = $this->deckManager->getCardOnTop($location);
        if (
            $topCard === null
            || intval($topCard['id']) !== intval($this->getGameStateValue($state))
        ) {
            return null;
        }
        return $this->withFaceDown($topCard, false);
    }

    private function clearDeckRevealIfCardLeaves(
        int $cardId,
        string $location
    ): void {
        $state = self::REVEALED_DECK_CARD_STATES[$location] ?? null;
        if (
            $state !== null
            && intval($this->getGameStateValue($state)) === $cardId
        ) {
            $this->setGameStateValue($state, 0);
        }
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
        if ($location === Location::GRAVEYARD) {
            $this->deckManager->insertCardOnExtremePosition(
                $card_id,
                $location,
                true
            );
        } else {
            $this->deckManager->moveCard($card_id, $location, $location_arg);
        }
        $card = $this->deckManager->getCard($card_id);
        $card_name = $card['card_name'];
        $notificationArguments = array(
            'card' => $card,
            'destination' => $location,
            'source' => $source
        );
        if ($source === Location::GRAVEYARD && $location !== Location::GRAVEYARD) {
            $graveyardTop = $this->deckManager->getCardOnTop(
                Location::GRAVEYARD
            );
            $notificationArguments['graveyardNb'] = $this->deckManager
                ->countCardInLocation(Location::GRAVEYARD);
            $notificationArguments['graveyardTop'] = $graveyardTop === null
                ? null
                : $this->deckManager->generateFakeCard($graveyardTop);
        }
        $this->notify->all(
            'cardMoved',
            \clienttranslate("Card $card_name moved from $source to $location"),
            $notificationArguments
        );
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
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
        ];

        switch ($consequence['action']) {
            case 'draw':
                $numCards = intval($consequence['number'] ?? 0);
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
                        if ($this->getCharactersInHand() === []) {
                            $this->moveCard(intval($card['id']), Location::GRAVEYARD);
                            $outcome['checkLoss'] = true;
                        } else {
                            $outcome['buryCharacterChoices'][] = [
                                'sourceCardId' => intval($card['id']),
                            ];
                        }
                        break;
                    case 'topCard':
                        $availableDecks = $this->getAvailableDrawDecks();
                        if ($availableDecks === []) {
                            $this->moveCard(intval($card['id']), Location::GRAVEYARD);
                            $outcome['checkLoss'] = true;
                        } elseif (count($availableDecks) === 1) {
                            $this->buryTopCardOfDeck($availableDecks[0]);
                            $outcome['checkLoss'] = true;
                        } else {
                            $outcome['buryTopCardChoices'][] = [
                                'sourceCardId' => intval($card['id']),
                            ];
                        }
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
            case 'heal':
                $number = intval($consequence['number'] ?? 0);
                if ($number < 1) {
                    throw new SystemException('Invalid heal character count');
                }
                $conditions = [];
                foreach (['condition', 'condition2'] as $conditionKey) {
                    if (!isset($consequence[$conditionKey])) {
                        continue;
                    }
                    $condition = strval($consequence[$conditionKey]);
                    if (!in_array($condition, self::DISASTER_CHARACTERISTICS, true)) {
                        throw new SystemException('Invalid heal condition');
                    }
                    $conditions[] = $condition;
                }
                $outcome['healChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                    'number' => $number,
                    'conditions' => array_values(array_unique($conditions)),
                ];
                break;
            case 'disaster':
            case 'disasterignore':
            case 'disasteraggravate2':
                $number = intval($consequence['number'] ?? 0);
                if ($number < 1) {
                    throw new SystemException('Invalid disaster count');
                }
                $consequence['number'] = $number;
                $outcome['disasterChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                    'consequence' => $consequence,
                ];
                break;
            case 'wolftrap':
                $outcome['wolfTrapChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                ];
                break;
            case 'recover':
                $outcome['recoverChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                ];
                break;
            case 'unremember':
                $outcome['unrememberChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                ];
                break;
            case 'reveal':
                $this->revealTopDeckCard(Location::RURAL);
                $this->revealTopDeckCard(Location::URBAN);
                break;
            case 'removedisaster':
                $characteristic = strval($consequence['disaster'] ?? '');
                if (!in_array(
                    $characteristic,
                    self::DISASTER_CHARACTERISTICS,
                    true
                )) {
                    throw new SystemException(
                        'Invalid removedisaster characteristic'
                    );
                }
                $this->removeSingleCharacteristicDisasters($characteristic);
                break;
            case 'draftdisaster':
                $outcome['draftDisasterChoices'][] = [
                    'sourceCardId' => intval($card['id']),
                ];
                break;
            case 'addemptydisasters':
                $emptyDisasters = $this->disasterManager->getCardsInLocation(
                    'reserve'
                );
                if ($emptyDisasters !== []) {
                    $this->disasterManager->moveAllCardsInLocation(
                        'reserve',
                        'deck'
                    );
                    $this->disasterManager->shuffle('deck');
                    $this->notify->all(
                        'emptyDisastersAdded',
                        \clienttranslate('${count} empty disaster token(s) added to the bag'),
                        [
                            'count' => count($emptyDisasters),
                            'disasters' => $emptyDisasters,
                        ]
                    );
                }
                break;
            case 'forcePass':
                $outcome['startNormalDraw'] = true;
                break;
            case 'escapeTalla':
                $outcome['escapeTallaChoice'] = true;
                break;
            case 'unearth':
                $graveyardTop = $this->deckManager->getCardOnTop(
                    Location::GRAVEYARD
                );
                if ($graveyardTop !== null) {
                    $this->moveCard(
                        intval($graveyardTop['id']),
                        Location::ESCAPED
                    );
                }
                break;
            case 'brainstorm':
                $outcome['brainstorm'] = true;
                break;
            case 'fastmemorise':
                $from = strval($consequence['from'] ?? '');
                if (!in_array($from, ['deck', Location::HAND], true)) {
                    throw new SystemException('Invalid fastmemorise source');
                }
                $outcome['fastMemorise'] = $from;
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
                    case 'hand2':
                        $outcome['avoidHandChoice'] = true;
                        break;
                    case 'memory':
                        $memoryTop = $this->deckManager->getCardOnTop(
                            Location::MEMORY
                        );
                        if ($memoryTop !== null) {
                            $this->moveCard(
                                intval($memoryTop['id']),
                                Location::ESCAPED
                            );
                        }
                        break;
                    case 'deck':
                        $availableDecks = $this->getAvailableDrawDecks();
                        if (count($availableDecks) === 1) {
                            $this->escapeTopCardsOfDeck($availableDecks[0], 2);
                        } elseif (count($availableDecks) === 2) {
                            $outcome['avoidDeckChoices'][] = [
                                'sourceCardId' => intval($card['id']),
                            ];
                        }
                        break;
                    case 'bothdeck':
                        foreach ([Location::URBAN, Location::RURAL] as $location) {
                            $deckTop = $this->deckManager->getCardOnTop($location);
                            if ($deckTop === null) {
                                continue;
                            }

                            $cardId = intval($deckTop['id']);
                            $this->clearDeckRevealIfCardLeaves($cardId, $location);
                            $destination = intval($deckTop['is_character'] ?? 0) === 1
                                ? Location::GRAVEYARD
                                : Location::ESCAPED;
                            $this->moveCard($cardId, $destination);
                            if ($destination === Location::GRAVEYARD) {
                                $outcome['checkLoss'] = true;
                            }
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
    private function applyConsequences(
        array $card,
        string $color,
        ?array $consequenceOverride = null
    ): array
    {
        $consequence = $consequenceOverride
            ?? ($card['consequence_' . $color] ?? null);
        $outcome = [
            'additionalDraws' => 0,
            'startNormalDraw' => false,
            'checkLoss' => false,
            'escapeTallaChoice' => false,
            'avoidZombieChoice' => false,
            'avoidHandChoice' => false,
            'brainstorm' => false,
            'fastMemorise' => false,
            'biteChoices' => [],
            'healChoices' => [],
            'buryCharacterChoices' => [],
            'buryTopCardChoices' => [],
            'draftDisasterChoices' => [],
            'avoidDeckChoices' => [],
            'disasterChoices' => [],
            'wolfTrapChoices' => [],
            'recoverChoices' => [],
            'unrememberChoices' => [],
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
            if ($action === 'wolftrap' && $color !== 'black') {
                throw new SystemException(
                    'Wolf Trap consequence is only valid as a black consequence'
                );
            }
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
            $outcome['avoidHandChoice'] = $outcome['avoidHandChoice'] || $currentOutcome['avoidHandChoice'];
            $outcome['brainstorm'] = $outcome['brainstorm'] || $currentOutcome['brainstorm'];
            if ($currentOutcome['fastMemorise'] !== false) {
                if ($outcome['fastMemorise'] !== false) {
                    throw new SystemException(
                        'Multiple fastmemorise consequences cannot be resolved together'
                    );
                }
                $outcome['fastMemorise'] = $currentOutcome['fastMemorise'];
            }
            $outcome['biteChoices'] = array_merge(
                $outcome['biteChoices'],
                $currentOutcome['biteChoices']
            );
            $outcome['healChoices'] = array_merge(
                $outcome['healChoices'],
                $currentOutcome['healChoices']
            );
            $outcome['disasterChoices'] = array_merge(
                $outcome['disasterChoices'],
                $currentOutcome['disasterChoices']
            );
            $outcome['buryCharacterChoices'] = array_merge(
                $outcome['buryCharacterChoices'],
                $currentOutcome['buryCharacterChoices']
            );
            $outcome['buryTopCardChoices'] = array_merge(
                $outcome['buryTopCardChoices'],
                $currentOutcome['buryTopCardChoices']
            );
            $outcome['draftDisasterChoices'] = array_merge(
                $outcome['draftDisasterChoices'],
                $currentOutcome['draftDisasterChoices']
            );
            $outcome['avoidDeckChoices'] = array_merge(
                $outcome['avoidDeckChoices'],
                $currentOutcome['avoidDeckChoices']
            );
            $outcome['wolfTrapChoices'] = array_merge(
                $outcome['wolfTrapChoices'],
                $currentOutcome['wolfTrapChoices']
            );
            $outcome['recoverChoices'] = array_merge(
                $outcome['recoverChoices'],
                $currentOutcome['recoverChoices']
            );
            $outcome['unrememberChoices'] = array_merge(
                $outcome['unrememberChoices'],
                $currentOutcome['unrememberChoices']
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

    private function getAvailableCharacterWoundCapacity(): int
    {
        $capacity = 0;
        foreach ($this->deckManager->getCardsInLocation(
            Location::CHARACTERS_IN_PLAY
        ) as $character) {
            $capacity += max(
                0,
                $this->getCharacterWoundLimit($character)
                    - intval($character['wounds'] ?? 0)
            );
        }
        return $capacity;
    }

    private function getCharacterWoundLimit(array $character): int
    {
        return ($character['card_name'] ?? '') === self::ROBERT_CARD_NAME
            ? 3
            : 4;
    }

    private function applyCharacterWounds(
        array $character,
        int $wounds,
        bool $deferFatalMove = false
    ): void {
        $currentWounds = intval($character['wounds'] ?? 0);
        $newWounds = min(4, $currentWounds + $wounds);
        $cardId = intval($character['id']);

        if (
            ($character['card_name'] ?? '') === self::ROBERT_CARD_NAME
            && $currentWounds < 3
            && $newWounds >= 3
        ) {
            $this->moveCard($cardId, Location::DONE);
            return;
        }

        if ($newWounds === 4 && !$deferFatalMove) {
            $this->moveCard($cardId, Location::GRAVEYARD);
            return;
        }

        $card = $this->deckManager->setCardWounds($cardId, $newWounds);
        if ($deferFatalMove) {
            $card['face_down'] = $newWounds === 4;
        }
        $this->notify->all(
            'characterWoundsChanged',
            \clienttranslate('${card_name} receives ${wounds} wound(s)'),
            [
                'card' => $card,
                'card_name' => $card['card_name'],
                'wounds' => $newWounds - $currentWounds,
            ]
        );
    }

    private function hasCharactersInPlay(): bool
    {
        return $this->deckManager->countCardInLocation(
            Location::CHARACTERS_IN_PLAY
        ) > 0;
    }

    public function actApplyBiteWounds(string $wound_allocations): void
    {
        $this->checkAction('actApplyBiteWounds');

        $event = $this->getPendingBiteChoiceEvent();
        try {
            $submittedAllocations = json_decode(
                $wound_allocations,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new UserException(
                \clienttranslate('Invalid wound allocation')
            );
        }
        if (!is_array($submittedAllocations)) {
            throw new UserException(\clienttranslate('Invalid wound allocation'));
        }

        $eligibleCharacters = $this->deckManager->getCardsInLocation(
            Location::CHARACTERS_IN_PLAY
        );
        $eligibleCharactersById = [];
        $availableWoundCapacity = 0;
        foreach ($eligibleCharacters as $character) {
            $eligibleCharactersById[intval($character['id'])] = $character;
            $availableWoundCapacity += max(
                0,
                $this->getCharacterWoundLimit($character)
                    - intval($character['wounds'] ?? 0)
            );
        }

        $allocations = [];
        foreach ($submittedAllocations as $cardId => $wounds) {
            if (!is_int($cardId) && !ctype_digit($cardId)) {
                throw new UserException(
                    \clienttranslate('Invalid wound allocation')
                );
            }
            $cardId = intval($cardId);
            if (
                $cardId < 1
                || !is_int($wounds)
                || $wounds < 1
                || !isset($eligibleCharactersById[$cardId])
            ) {
                throw new UserException(
                    \clienttranslate('Invalid wound allocation')
                );
            }

            $currentWounds = intval(
                $eligibleCharactersById[$cardId]['wounds'] ?? 0
            );
            if (
                $currentWounds + $wounds
                    > $this->getCharacterWoundLimit(
                        $eligibleCharactersById[$cardId]
                    )
            ) {
                throw new UserException(
                    \clienttranslate('A character cannot receive that many wounds')
                );
            }
            $allocations[$cardId] = $wounds;
        }

        $woundsToAssign = min(
            intval($event['parameters']['bite']),
            $availableWoundCapacity
        );
        if (array_sum($allocations) !== $woundsToAssign) {
            throw new UserException(
                \clienttranslate('You must assign all possible wounds')
            );
        }

        foreach ($allocations as $cardId => $wounds) {
            $this->applyCharacterWounds(
                $eligibleCharactersById[$cardId],
                $wounds
            );
        }

        $this->popEvent($event['id']);
        if ($this->isLossReached()) {
            $this->notify->all(
                'gameLoss',
                \clienttranslate('You lost the game')
            );
            $this->gamestate->nextState(Transition::GAME_END);
            return;
        }
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

    public function argBuryCharacterChoice(): array
    {
        $event = $this->getPendingBuryCharacterChoiceEvent();
        $characters = $this->getCharactersInHand();

        return [
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

    public function actBuryCharacter(int $card_id): void
    {
        $this->checkAction('actBuryCharacter');
        $event = $this->getPendingBuryCharacterChoiceEvent();

        $eligibleCharactersById = [];
        foreach ($this->getCharactersInHand() as $character) {
            $eligibleCharactersById[intval($character['id'])] = $character;
        }
        if (!isset($eligibleCharactersById[$card_id])) {
            throw new UserException(
                \clienttranslate('You must choose a character from your hand')
            );
        }

        $this->moveCard($card_id, Location::GRAVEYARD);
        $this->popEvent($event['id']);

        if ($this->isLossReached()) {
            $this->notify->all(
                'gameLoss',
                \clienttranslate('You lost the game')
            );
            $this->gamestate->nextState(Transition::GAME_END);
            return;
        }
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingBuryCharacterChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if (
            $event === null
            || $event['type'] !== EventType::BURY_CHARACTER_CHOICE
            || intval($event['parameters']['sourceCardId'] ?? 0) < 1
        ) {
            throw new SystemException('There is no pending bury character choice');
        }
        return $event;
    }

    private function getCharactersInHand(): array
    {
        return array_values(array_filter(
            $this->deckManager->getCardsInLocation(Location::HAND),
            static function (array $card): bool {
                return intval($card['is_character'] ?? 0) === 1;
            }
        ));
    }

    public function argHealChoice(): array
    {
        $event = $this->getPendingHealChoiceEvent();
        $characters = $this->getEligibleCharactersForHeal($event);

        return [
            'number' => intval($event['parameters']['number']),
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

    public function actHealCharacters(string $selected_character_ids): void
    {
        $this->checkAction('actHealCharacters');
        $event = $this->getPendingHealChoiceEvent();

        try {
            $submittedIds = json_decode(
                $selected_character_ids,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new UserException(
                \clienttranslate('Invalid character selection')
            );
        }
        if (!is_array($submittedIds) || $submittedIds !== array_values($submittedIds)) {
            throw new UserException(
                \clienttranslate('Invalid character selection')
            );
        }

        $maximum = intval($event['parameters']['number']);
        if (count($submittedIds) > $maximum) {
            throw new UserException(
                \clienttranslate('Too many characters selected')
            );
        }

        $eligibleCharactersById = [];
        foreach ($this->getEligibleCharactersForHeal($event) as $character) {
            $eligibleCharactersById[intval($character['id'])] = $character;
        }

        $selectedIds = [];
        foreach ($submittedIds as $cardId) {
            if (!is_int($cardId) || $cardId < 1
                || !isset($eligibleCharactersById[$cardId])
                || isset($selectedIds[$cardId])) {
                throw new UserException(
                    \clienttranslate('Invalid character selection')
                );
            }
            $selectedIds[$cardId] = true;
        }

        foreach (array_keys($selectedIds) as $cardId) {
            $currentWounds = intval(
                $eligibleCharactersById[$cardId]['wounds'] ?? 0
            );
            if ($currentWounds === 0) {
                continue;
            }

            $card = $this->deckManager->setCardWounds(
                $cardId,
                $currentWounds - 1
            );
            $this->notify->all(
                'characterWoundsChanged',
                \clienttranslate('${card_name} heals 1 wound'),
                [
                    'card' => $card,
                    'card_name' => $card['card_name'],
                    'wounds' => -1,
                ]
            );
        }

        $this->popEvent($event['id']);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingHealChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if ($event === null || $event['type'] !== EventType::HEAL_CHOICE) {
            throw new SystemException('There is no pending heal choice');
        }
        if (
            intval($event['parameters']['sourceCardId'] ?? 0) < 1
            || intval($event['parameters']['number'] ?? 0) < 1
        ) {
            throw new SystemException('Invalid pending heal choice');
        }
        $conditions = $event['parameters']['conditions'] ?? [];
        if (!is_array($conditions) || $conditions !== array_values($conditions)) {
            throw new SystemException('Invalid pending heal conditions');
        }
        foreach ($conditions as $condition) {
            if (!is_string($condition) || !in_array(
                $condition,
                self::DISASTER_CHARACTERISTICS,
                true
            )) {
                throw new SystemException('Invalid pending heal condition');
            }
        }
        return $event;
    }

    private function getEligibleCharactersForHeal(array $event): array
    {
        $conditions = $event['parameters']['conditions'] ?? [];
        $characters = array_values(
            $this->deckManager->getCardsInLocation(
                Location::CHARACTERS_IN_PLAY
            )
        );
        if ($conditions === []) {
            return $characters;
        }

        return array_values(array_filter(
            $characters,
            static function (array $character) use ($conditions): bool {
                foreach ($conditions as $condition) {
                    if (intval($character['weakness_' . $condition] ?? 0) === 1) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }

    public function argDraftDisasterChoice(): array
    {
        $event = $this->getPendingDraftDisasterChoiceEvent();
        $drawnDisasters = array_values(
            $this->disasterManager->getCardsInLocation('hand', $event['id'])
        );

        return [
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'phase' => $drawnDisasters === [] ? 'draw' : 'choose',
            'drawnDisasters' => $drawnDisasters,
            'eligibleDisasterIds' => array_map(
                'intval',
                array_column($drawnDisasters, 'id')
            ),
        ];
    }

    public function actDrawDraftDisasters(): void
    {
        $this->checkAction('actDrawDraftDisasters');
        $event = $this->getPendingDraftDisasterChoiceEvent();
        if ($this->disasterManager->getCardsInLocation('hand', $event['id']) !== []) {
            throw new UserException(
                \clienttranslate('The disasters have already been drawn')
            );
        }

        $this->disasterManager->moveAllCardsInLocation('hand', 'deck');
        $this->disasterManager->shuffle('deck');
        $numberToDraw = min(
            2,
            $this->disasterManager->countCardInLocation('deck')
        );
        if ($numberToDraw === 0) {
            $this->popEvent($event['id']);
            $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
            return;
        }

        for ($drawIndex = 0; $drawIndex < $numberToDraw; $drawIndex++) {
            $disaster = $this->disasterManager->pickCard('deck', $event['id']);
            if ($disaster === null) {
                throw new SystemException('The disaster deck is empty');
            }
            $this->notify->all(
                'disasterDrawnFromBag',
                \clienttranslate('Disaster drawn from bag for draft'),
                [
                    'disaster' => $disaster,
                    'shuffle' => $drawIndex === 0,
                ]
            );
        }
        $this->gamestate->nextState(Transition::DRAFT_DISASTER_CHOICE);
    }

    public function actResolveDraftDisaster(int $disaster_id): void
    {
        $this->checkAction('actResolveDraftDisaster');
        $event = $this->getPendingDraftDisasterChoiceEvent();
        $drawnDisasters = array_values(
            $this->disasterManager->getCardsInLocation('hand', $event['id'])
        );
        $drawnDisastersById = [];
        foreach ($drawnDisasters as $disaster) {
            $drawnDisastersById[intval($disaster['id'])] = $disaster;
        }
        if (
            count($drawnDisastersById) < 1
            || count($drawnDisastersById) > 2
            || !isset($drawnDisastersById[$disaster_id])
        ) {
            throw new UserException(
                \clienttranslate('You must choose one of the drafted disasters')
            );
        }

        $removedDisaster = $drawnDisastersById[$disaster_id];
        $returnedDisasters = [];
        foreach ($drawnDisastersById as $drawnId => $disaster) {
            if ($drawnId === $disaster_id) {
                $this->disasterManager->moveCard(
                    $drawnId,
                    self::REMOVED_DISASTER_LOCATION
                );
                continue;
            }
            $this->disasterManager->moveCard($drawnId, 'deck');
            $returnedDisasters[] = $disaster;
        }
        $this->disasterManager->shuffle('deck');
        $this->notify->all(
            'draftDisasterResolved',
            \clienttranslate('The selected drafted disaster is destroyed'),
            [
                'removedDisaster' => $removedDisaster,
                'returnedDisasters' => $returnedDisasters,
            ]
        );

        $this->popEvent($event['id']);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingDraftDisasterChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if (
            $event === null
            || $event['type'] !== EventType::DRAFT_DISASTER_CHOICE
            || intval($event['parameters']['sourceCardId'] ?? 0) < 1
        ) {
            throw new SystemException('There is no pending draft disaster choice');
        }
        return $event;
    }

    public function argDisasterChoice(): array
    {
        $event = $this->getPendingDisasterChoiceEvent();
        $resolution = $this->getDisasterResolution($event);
        $normalizedResolution = $this->skipInactiveDisasterCharacteristics(
            $event,
            $resolution
        );
        if ($normalizedResolution !== $resolution) {
            $resolution = $normalizedResolution;
            $this->updateDisasterResolution($event, $resolution);
        }
        $consequence = $event['parameters']['consequence'];
        $requiredDraws = intval($consequence['number']);
        $drawnDisasters = array_values(
            $this->disasterManager->getCardsInLocation('hand', $event['id'])
        );

        if ($resolution['pendingDrawCardId'] > 0) {
            $phase = 'confirmDraw';
        } elseif ($resolution['confirmedDraws'] < $requiredDraws) {
            $phase = 'draw';
        } elseif ($resolution['characteristicIndex'] < count(self::DISASTER_CHARACTERISTICS)) {
            $phase = 'characteristic';
        } else {
            $phase = 'complete';
        }

        $characteristic = $phase === 'characteristic'
            ? self::DISASTER_CHARACTERISTICS[$resolution['characteristicIndex']]
            : null;
        $characteristicPresent = $characteristic === null
            ? false
            : $this->disasterCharacteristicIsPresent(
                $characteristic,
                $drawnDisasters,
                $consequence
            );
        $characteristicIgnored = $characteristic !== null
            && in_array(
                $characteristic,
                $resolution['ignoredCharacteristics'],
                true
            );
        $present = $characteristicPresent && !$characteristicIgnored;
        $resourceId = $characteristic === null
            ? null
            : 'ressource_' . $characteristic;
        $resourceAvailable = $phase === 'characteristic'
            && $characteristicPresent
            && !$characteristicIgnored
            && $this->ressources->getRessourceState($resourceId) === 0;
        $affectedCharacters = $present
            ? $this->getCharactersAffectedByDisaster($characteristic)
            : [];
        return [
            'consequence' => $consequence,
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'phase' => $phase,
            'requiredDraws' => $requiredDraws,
            'confirmedDraws' => $resolution['confirmedDraws'],
            'drawnDisasters' => $drawnDisasters,
            'characteristic' => $characteristic,
            'characteristicPresent' => $present,
            'characteristicIgnored' => $characteristicIgnored,
            'resourceId' => $resourceId,
            'resourceAvailable' => $resourceAvailable,
            'affectedCharacters' => $affectedCharacters,
        ];
    }

    private function removeSingleCharacteristicDisasters(
        string $characteristic
    ): void {
        $otherCharacteristics = array_values(array_diff(
            self::DISASTER_CHARACTERISTICS,
            [$characteristic]
        ));
        $removedDisasters = [];
        foreach ($this->disasterManager->getCardsInLocation('deck') as $disaster) {
            if (
                intval($disaster['disaster_' . $characteristic] ?? 0) !== 1
                || intval(
                    $disaster['disaster_' . $otherCharacteristics[0]] ?? 0
                ) !== 0
                || intval(
                    $disaster['disaster_' . $otherCharacteristics[1]] ?? 0
                ) !== 0
            ) {
                continue;
            }

            $this->disasterManager->moveCard(
                intval($disaster['id']),
                self::REMOVED_DISASTER_LOCATION
            );
            $removedDisasters[] = $disaster;
        }

        if ($removedDisasters !== []) {
            $this->notify->all(
                'disastersRemoved',
                \clienttranslate('${count} disaster(s) with only ${characteristic} removed'),
                [
                    'count' => count($removedDisasters),
                    'characteristic' => $characteristic,
                    'disasters' => $removedDisasters,
                ]
            );
        }
    }

    public function actDrawDisaster(): void
    {
        $this->checkAction('actDrawDisaster');
        $event = $this->getPendingDisasterChoiceEvent();
        $resolution = $this->getDisasterResolution($event);
        $requiredDraws = intval($event['parameters']['consequence']['number']);
        if (
            $resolution['pendingDrawCardId'] > 0
            || $resolution['confirmedDraws'] >= $requiredDraws
        ) {
            throw new UserException(
                \clienttranslate('The required disasters have already been drawn')
            );
        }

        $shuffle = false;
        if ($resolution['confirmedDraws'] === 0) {
            $this->disasterManager->moveAllCardsInLocation('hand', 'deck');
            $this->disasterManager->shuffle('deck');
            $shuffle = true;
        }

        $drawsRemaining = $requiredDraws - $resolution['confirmedDraws'];
        for ($drawIndex = 0; $drawIndex < $drawsRemaining; $drawIndex++) {
            $disaster = $this->disasterManager->pickCard('deck', $event['id']);
            if ($disaster === null) {
                throw new SystemException('The disaster deck is empty');
            }

            $isFinalDraw = $drawIndex === $drawsRemaining - 1;
            if ($isFinalDraw) {
                $resolution['pendingDrawCardId'] = intval($disaster['id']);
            } else {
                $resolution['confirmedDraws']++;
            }
            $this->notify->all(
                'disasterDrawnFromBag',
                \clienttranslate('Disaster drawn from bag'),
                [
                    'disaster' => $disaster,
                    'shuffle' => $shuffle && $drawIndex === 0,
                ]
            );
        }
        $this->updateDisasterResolution($event, $resolution);
        $this->gamestate->nextState(Transition::DISASTER_CHOICE);
    }

    public function actConfirmDisasterDraw(): void
    {
        $this->checkAction('actConfirmDisasterDraw');
        $event = $this->getPendingDisasterChoiceEvent();
        $resolution = $this->getDisasterResolution($event);
        if ($resolution['pendingDrawCardId'] < 1) {
            throw new UserException(\clienttranslate('There is no disaster draw to confirm'));
        }

        $drawnIds = array_map(
            'intval',
            array_column(
                $this->disasterManager->getCardsInLocation('hand', $event['id']),
                'id'
            )
        );
        if (!in_array($resolution['pendingDrawCardId'], $drawnIds, true)) {
            throw new SystemException('The pending disaster is no longer drawn');
        }

        $resolution['confirmedDraws']++;
        $resolution['pendingDrawCardId'] = 0;
        $resolution = $this->skipInactiveDisasterCharacteristics(
            $event,
            $resolution
        );
        $this->updateDisasterResolution($event, $resolution);
        $this->gamestate->nextState(Transition::DISASTER_CHOICE);
    }

    public function actConfirmDisasterCharacteristic(): void
    {
        $this->checkAction('actConfirmDisasterCharacteristic');
        $event = $this->getPendingDisasterChoiceEvent();
        $resolution = $this->getDisasterResolution($event);
        $requiredDraws = intval($event['parameters']['consequence']['number']);
        if (
            $resolution['pendingDrawCardId'] > 0
            || $resolution['confirmedDraws'] !== $requiredDraws
            || $resolution['characteristicIndex'] >= count(self::DISASTER_CHARACTERISTICS)
        ) {
            throw new UserException(\clienttranslate('This disaster characteristic cannot be confirmed yet'));
        }

        $characteristic = self::DISASTER_CHARACTERISTICS[$resolution['characteristicIndex']];
        $drawnDisasters = $this->disasterManager->getCardsInLocation(
            'hand',
            $event['id']
        );
        if (
            !in_array(
                $characteristic,
                $resolution['ignoredCharacteristics'],
                true
            )
            && $this->disasterCharacteristicIsPresent(
            $characteristic,
            $drawnDisasters,
            $event['parameters']['consequence']
            )
        ) {
            foreach ($this->getCharactersAffectedByDisaster($characteristic) as $character) {
                $this->applyCharacterWounds($character, 1, true);
            }
        }

        $resolution['characteristicIndex']++;
        $resolution = $this->skipInactiveDisasterCharacteristics(
            $event,
            $resolution
        );
        $this->updateDisasterResolution($event, $resolution);
        $this->gamestate->nextState(Transition::DISASTER_CHOICE);
    }

    public function actUseDisasterResource(string $token_id): void
    {
        $this->checkAction('actUseDisasterResource');
        $event = $this->getPendingDisasterChoiceEvent();
        $resolution = $this->getDisasterResolution($event);
        $requiredDraws = intval($event['parameters']['consequence']['number']);
        if (
            $resolution['pendingDrawCardId'] > 0
            || $resolution['confirmedDraws'] !== $requiredDraws
            || $resolution['characteristicIndex'] >= count(self::DISASTER_CHARACTERISTICS)
        ) {
            throw new UserException(\clienttranslate('No disaster characteristic can be ignored now'));
        }

        $characteristic = self::DISASTER_CHARACTERISTICS[$resolution['characteristicIndex']];
        $expectedResourceId = 'ressource_' . $characteristic;
        $drawnDisasters = $this->disasterManager->getCardsInLocation(
            'hand',
            $event['id']
        );
        if (
            $token_id !== $expectedResourceId
            || in_array(
                $characteristic,
                $resolution['ignoredCharacteristics'],
                true
            )
            || !$this->disasterCharacteristicIsPresent(
                $characteristic,
                $drawnDisasters,
                $event['parameters']['consequence']
            )
            || $this->ressources->getRessourceState($token_id) !== 0
        ) {
            throw new UserException(
                \clienttranslate('This resource cannot ignore the current disaster characteristic')
            );
        }

        $this->ressources->consumeRessources($token_id);
        $resolution['ignoredCharacteristics'][] = $characteristic;
        $this->updateDisasterResolution($event, $resolution);
        $this->gamestate->nextState(Transition::DISASTER_CHOICE);
    }

    public function actResolveDisaster(): void
    {
        $this->checkAction('actResolveDisaster');

        $event = $this->getPendingDisasterChoiceEvent();
        $resolution = $this->getDisasterResolution($event);
        if (
            $resolution['pendingDrawCardId'] > 0
            || $resolution['confirmedDraws'] !== intval(
                $event['parameters']['consequence']['number']
            )
            || $resolution['characteristicIndex'] !== count(self::DISASTER_CHARACTERISTICS)
        ) {
            throw new UserException(\clienttranslate('The disaster resolution is not complete'));
        }

        foreach ($this->deckManager->getCardsInLocation(
            Location::CHARACTERS_IN_PLAY
        ) as $character) {
            if (intval($character['wounds'] ?? 0) >= 4) {
                $this->moveCard(intval($character['id']), Location::GRAVEYARD);
            }
        }
        $this->disasterManager->moveAllCardsInLocation(
            'hand',
            'deck',
            $event['id']
        );
        $this->disasterManager->shuffle('deck');
        $this->notify->all(
            'disasterShuffledBack',
            \clienttranslate('Disasters shuffled back into the bag')
        );
        $this->popEvent($event['id']);
        if ($this->isLossReached()) {
            $this->notify->all('gameLoss', \clienttranslate('You lost the game'));
            $this->gamestate->nextState(Transition::GAME_END);
            return;
        }
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getDisasterResolution(array $event): array
    {
        $resolution = $event['parameters']['resolution'] ?? [];
        return [
            'confirmedDraws' => max(0, intval($resolution['confirmedDraws'] ?? 0)),
            'pendingDrawCardId' => max(0, intval($resolution['pendingDrawCardId'] ?? 0)),
            'characteristicIndex' => max(0, intval($resolution['characteristicIndex'] ?? 0)),
            'ignoredCharacteristics' => array_values(array_intersect(
                self::DISASTER_CHARACTERISTICS,
                is_array($resolution['ignoredCharacteristics'] ?? null)
                    ? $resolution['ignoredCharacteristics']
                    : []
            )),
        ];
    }

    private function updateDisasterResolution(array $event, array $resolution): void
    {
        $parameters = $event['parameters'];
        $parameters['resolution'] = $resolution;
        $this->eventStack->updateEventParameters($event['id'], $parameters);
    }

    private function skipInactiveDisasterCharacteristics(
        array $event,
        array $resolution
    ): array {
        $requiredDraws = intval($event['parameters']['consequence']['number']);
        if (
            $resolution['pendingDrawCardId'] > 0
            || $resolution['confirmedDraws'] !== $requiredDraws
        ) {
            return $resolution;
        }

        $drawnDisasters = $this->disasterManager->getCardsInLocation(
            'hand',
            $event['id']
        );
        while ($resolution['characteristicIndex'] < count(self::DISASTER_CHARACTERISTICS)) {
            $characteristic = self::DISASTER_CHARACTERISTICS[
                $resolution['characteristicIndex']
            ];
            if ($this->disasterCharacteristicIsPresent(
                $characteristic,
                $drawnDisasters,
                $event['parameters']['consequence']
            )) {
                break;
            }
            $resolution['characteristicIndex']++;
        }
        return $resolution;
    }

    private function disasterCharacteristicIsPresent(
        string $characteristic,
        array $drawnDisasters,
        array $consequence
    ): bool {
        if (
            $consequence['action'] === 'disasterignore'
            && strval($consequence['ignore'] ?? '') === $characteristic
        ) {
            return false;
        }
        if (
            $consequence['action'] === 'disasteraggravate2'
            && in_array(
                $characteristic,
                [
                    strval($consequence['aggravate1'] ?? ''),
                    strval($consequence['aggravate2'] ?? ''),
                ],
                true
            )
        ) {
            return true;
        }

        $field = 'disaster_' . $characteristic;
        foreach ($drawnDisasters as $disaster) {
            if (intval($disaster[$field] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    private function getCharactersAffectedByDisaster(string $characteristic): array
    {
        $field = 'weakness_' . $characteristic;
        return array_values(array_filter(
            $this->deckManager->getCardsInLocation(
                Location::CHARACTERS_IN_PLAY
            ),
            static function (array $character) use ($field): bool {
                return intval($character[$field] ?? 0) === 1
                    && intval($character['wounds'] ?? 0) < 4;
            }
        ));
    }

    private function getPendingDisasterChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if ($event === null || $event['type'] !== EventType::DISASTER_CHOICE) {
            throw new SystemException('There is no pending disaster choice');
        }

        $sourceCardId = intval($event['parameters']['sourceCardId'] ?? 0);
        $consequence = $event['parameters']['consequence'] ?? null;
        $action = is_array($consequence)
            ? strval($consequence['action'] ?? '')
            : '';
        if (
            $sourceCardId < 1
            || intval($consequence['number'] ?? 0) < 1
            || !in_array(
                $action,
                ['disaster', 'disasterignore', 'disasteraggravate2'],
                true
            )
        ) {
            throw new SystemException('Invalid pending disaster choice');
        }

        return $event;
    }

    public function argWolfTrapChoice(): array
    {
        $event = $this->getPendingWolfTrapChoiceEvent();
        $drawnDisasters = array_values(
            $this->disasterManager->getCardsInLocation('hand', $event['id'])
        );

        return [
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'phase' => $drawnDisasters === [] ? 'draw' : 'resolve',
            'drawnDisasters' => $drawnDisasters,
            'willBury' => $this->wolfTrapDisasterRequiresBurial(
                $drawnDisasters
            ),
        ];
    }

    public function actDrawWolfTrapDisaster(): void
    {
        $this->checkAction('actDrawWolfTrapDisaster');
        $event = $this->getPendingWolfTrapChoiceEvent();
        if ($this->disasterManager->getCardsInLocation('hand', $event['id']) !== []) {
            throw new UserException(
                \clienttranslate('The Wolf Trap disaster has already been drawn')
            );
        }

        $this->disasterManager->shuffle('deck');
        $disaster = $this->disasterManager->pickCard('deck', $event['id']);
        if ($disaster === null) {
            throw new SystemException('The disaster deck is empty');
        }

        $this->notify->all(
            'disasterDrawnFromBag',
            \clienttranslate('Disaster drawn for Wolf Trap'),
            [
                'disaster' => $disaster,
                'shuffle' => true,
            ]
        );
        $this->gamestate->nextState(Transition::WOLF_TRAP_CHOICE);
    }

    public function actResolveWolfTrap(): void
    {
        $this->checkAction('actResolveWolfTrap');
        $event = $this->getPendingWolfTrapChoiceEvent();
        $drawnDisasters = array_values(
            $this->disasterManager->getCardsInLocation('hand', $event['id'])
        );
        if (count($drawnDisasters) !== 1) {
            throw new UserException(
                \clienttranslate('You must draw one disaster for Wolf Trap')
            );
        }

        if ($this->wolfTrapDisasterRequiresBurial($drawnDisasters)) {
            $sourceCard = $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            );
            if ($sourceCard !== null) {
                $this->moveCard(intval($sourceCard['id']), Location::GRAVEYARD);
            }
        }

        $this->disasterManager->moveAllCardsInLocation(
            'hand',
            'deck',
            $event['id']
        );
        $this->disasterManager->shuffle('deck');
        $this->notify->all(
            'disasterShuffledBack',
            \clienttranslate('Wolf Trap disaster shuffled back into the bag')
        );

        $this->popEvent($event['id']);
        if ($this->isLossReached()) {
            $this->notify->all('gameLoss', \clienttranslate('You lost the game'));
            $this->gamestate->nextState(Transition::GAME_END);
            return;
        }
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingWolfTrapChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if ($event === null || $event['type'] !== EventType::WOLF_TRAP_CHOICE) {
            throw new SystemException('There is no pending Wolf Trap choice');
        }
        $sourceCardId = intval($event['parameters']['sourceCardId'] ?? 0);
        if ($sourceCardId < 1 || $this->deckManager->getCard($sourceCardId) === null) {
            throw new SystemException('Invalid pending Wolf Trap choice');
        }
        return $event;
    }

    private function wolfTrapDisasterRequiresBurial(array $drawnDisasters): bool
    {
        foreach ($drawnDisasters as $disaster) {
            $hasBreak = intval($disaster['disaster_break'] ?? 0) === 1;
            $hasAnotherCharacteristic = intval(
                $disaster['disaster_hunger'] ?? 0
            ) === 1 || intval($disaster['disaster_stress'] ?? 0) === 1;
            if ($hasBreak && $hasAnotherCharacteristic) {
                return true;
            }
        }
        return false;
    }

    public function argRecoverChoice(): array
    {
        $event = $this->getPendingRecoverChoiceEvent();
        $eligibleCards = array_values(
            $this->deckManager->getCardsInLocation(Location::ESCAPED)
        );

        return [
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'eligibleCards' => $eligibleCards,
            'eligibleCardsIds' => array_map(
                'intval',
                array_column($eligibleCards, 'id')
            ),
        ];
    }

    public function actRecoverCard(int $card_id): void
    {
        $this->checkAction('actRecoverCard');
        $event = $this->getPendingRecoverChoiceEvent();
        $eligibleCardIds = array_map(
            'intval',
            array_column(
                $this->deckManager->getCardsInLocation(Location::ESCAPED),
                'id'
            )
        );
        if (!in_array($card_id, $eligibleCardIds, true)) {
            throw new UserException(
                \clienttranslate('You must choose a card from the escaped area')
            );
        }

        $this->moveCard($card_id, Location::HAND);
        $this->popEvent($event['id']);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingRecoverChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if ($event === null || $event['type'] !== EventType::RECOVER_CHOICE) {
            throw new SystemException('There is no pending recover choice');
        }
        $sourceCardId = intval($event['parameters']['sourceCardId'] ?? 0);
        if ($sourceCardId < 1 || $this->deckManager->getCard($sourceCardId) === null) {
            throw new SystemException('Invalid pending recover choice');
        }
        return $event;
    }

    private function getPendingUnrememberChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if (
            $event === null
            || $event['type'] !== EventType::UNREMEMBER_CHOICE
        ) {
            throw new SystemException(
                'There is no pending unremember choice'
            );
        }
        $sourceCardId = intval(
            $event['parameters']['sourceCardId'] ?? 0
        );
        if (
            $sourceCardId < 1
            || $this->deckManager->getCard($sourceCardId) === null
        ) {
            throw new SystemException('Invalid pending unremember choice');
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

    private function getAvailableDrawDecks(): array
    {
        return array_values(array_filter(
            [Location::RURAL, Location::URBAN],
            function (string $location): bool {
                return $this->deckManager->countCardInLocation($location) > 0;
            }
        ));
    }

    public function argAvoidDeckChoice(): array
    {
        $event = $this->getPendingAvoidDeckChoiceEvent();

        return [
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'availableDecks' => $this->getAvailableDrawDecks(),
        ];
    }

    public function actAvoidDeck(string $location): void
    {
        $this->checkAction('actAvoidDeck');
        $event = $this->getPendingAvoidDeckChoiceEvent();
        if (!in_array($location, $this->getAvailableDrawDecks(), true)) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('You cannot avoid cards from this deck'),
                ['location' => $location]
            ));
        }

        $this->escapeTopCardsOfDeck($location, 2);
        $this->popEvent($event['id']);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingAvoidDeckChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if (
            $event === null
            || $event['type'] !== EventType::AVOID_DECK_CHOICE
            || intval($event['parameters']['sourceCardId'] ?? 0) < 1
        ) {
            throw new SystemException('There is no pending avoid deck choice');
        }
        return $event;
    }

    private function escapeTopCardsOfDeck(string $location, int $number): void
    {
        if (
            !in_array($location, [Location::RURAL, Location::URBAN], true)
            || $number < 1
        ) {
            throw new SystemException('Invalid deck avoidance');
        }

        foreach ($this->deckManager->getCardsOnTop($number, $location) as $card) {
            $cardId = intval($card['id']);
            $this->clearDeckRevealIfCardLeaves($cardId, $location);
            $this->moveCard($cardId, Location::ESCAPED);
        }
    }

    public function argBuryTopCardChoice(): array
    {
        $event = $this->getPendingBuryTopCardChoiceEvent();

        return [
            'sourceCard' => $this->deckManager->getCard(
                intval($event['parameters']['sourceCardId'])
            ),
            'availableDecks' => $this->getAvailableDrawDecks(),
        ];
    }

    public function actBuryTopCard(string $location): void
    {
        $this->checkAction('actBuryTopCard');
        $event = $this->getPendingBuryTopCardChoiceEvent();
        if (!in_array($location, $this->getAvailableDrawDecks(), true)) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('You cannot bury a card from this deck'),
                ['location' => $location]
            ));
        }

        $this->buryTopCardOfDeck($location);
        $this->popEvent($event['id']);
        if ($this->isLossReached()) {
            $this->notify->all(
                'gameLoss',
                \clienttranslate('You lost the game')
            );
            $this->gamestate->nextState(Transition::GAME_END);
            return;
        }
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    private function getPendingBuryTopCardChoiceEvent(): array
    {
        $event = $this->eventStack->getCurrentEvent();
        if (
            $event === null
            || $event['type'] !== EventType::BURY_TOP_CARD_CHOICE
            || intval($event['parameters']['sourceCardId'] ?? 0) < 1
        ) {
            throw new SystemException('There is no pending bury top card choice');
        }
        return $event;
    }

    private function buryTopCardOfDeck(string $location): void
    {
        if (!in_array($location, [Location::RURAL, Location::URBAN], true)) {
            throw new SystemException('Invalid deck for bury top card');
        }
        $topCard = $this->deckManager->getCardOnTop($location);
        if ($topCard === null) {
            throw new SystemException('Cannot bury a card from an empty deck');
        }

        $cardId = intval($topCard['id']);
        $this->clearDeckRevealIfCardLeaves($cardId, $location);
        $this->moveCard($cardId, Location::GRAVEYARD);
    }

    public function argBrainstormDeckChoice(): array
    {
        return [
            'availableDecks' => $this->getAvailableDrawDecks(),
        ];
    }

    public function argFastMemoriseDeckChoice(): array
    {
        return [
            'availableDecks' => $this->getAvailableDrawDecks(),
        ];
    }

    public function argFastMemoriseHandChoice(): array
    {
        return [
            'maximumCards' => min(
                2,
                $this->deckManager->countCardInLocation(Location::HAND)
            ),
        ];
    }

    public function actFastMemorise(string $location): void
    {
        $this->checkAction('actFastMemorise');

        if (!in_array($location, $this->getAvailableDrawDecks(), true)) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('You cannot memorise from this deck'),
                ['location' => $location]
            ));
        }

        $cards = $this->deckManager->getCardsOnTop(2, $location);
        foreach (array_reverse($cards) as $card) {
            $cardId = intval($card['id']);
            $this->clearDeckRevealIfCardLeaves($cardId, $location);
            $this->deckManager->insertCardOnExtremePosition(
                $cardId,
                Location::MEMORY,
                true
            );
            $card = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is quickly memorised'),
                [
                    'card' => $card,
                    'card_name' => $card['card_name'],
                    'destination' => Location::MEMORY,
                    'source' => $location,
                ]
            );
        }

        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    public function actFastMemoriseFromHand(string $card_ids): void
    {
        $this->checkAction('actFastMemoriseFromHand');

        try {
            $submittedCardIds = json_decode(
                $card_ids,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new UserException(
                \clienttranslate('Invalid cards selected for quick memorisation')
            );
        }

        if (!is_array($submittedCardIds)) {
            throw new UserException(
                \clienttranslate('Invalid cards selected for quick memorisation')
            );
        }

        $selectedCardIds = array_map('intval', $submittedCardIds);
        if (
            count($selectedCardIds) > 2
            || count(array_unique($selectedCardIds)) !== count($selectedCardIds)
        ) {
            throw new UserException(
                \clienttranslate('You may select up to two different cards')
            );
        }

        $handCardsById = [];
        foreach ($this->deckManager->getCardsInLocation(Location::HAND) as $handCard) {
            $handCardsById[intval($handCard['id'])] = $handCard;
        }

        $cards = [];
        foreach ($selectedCardIds as $cardId) {
            if (!isset($handCardsById[$cardId])) {
                throw new UserException(
                    \clienttranslate('You can only quickly memorise cards from your hand')
                );
            }
            $cards[] = $handCardsById[$cardId];
        }

        foreach ($cards as $card) {
            $cardId = intval($card['id']);
            $this->deckManager->insertCardOnExtremePosition(
                $cardId,
                Location::MEMORY,
                true
            );
            $movedCard = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is quickly memorised from the hand'),
                [
                    'card' => $movedCard,
                    'card_name' => $movedCard['card_name'],
                    'destination' => Location::MEMORY,
                    'source' => Location::HAND,
                ]
            );
        }

        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    public function argAvoidHandChoice(): array
    {
        return [
            'maximumCards' => min(
                2,
                $this->deckManager->countCardInLocation(Location::HAND)
            ),
        ];
    }

    public function actAvoidFromHand(string $card_ids): void
    {
        $this->checkAction('actAvoidFromHand');

        try {
            $submittedCardIds = json_decode(
                $card_ids,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new UserException(
                \clienttranslate('Invalid cards selected to escape')
            );
        }

        if (!is_array($submittedCardIds)) {
            throw new UserException(
                \clienttranslate('Invalid cards selected to escape')
            );
        }

        $selectedCardIds = array_map('intval', $submittedCardIds);
        if (
            count($selectedCardIds) > 2
            || count(array_unique($selectedCardIds)) !== count($selectedCardIds)
        ) {
            throw new UserException(
                \clienttranslate('You may select up to two different cards')
            );
        }

        $handCardsById = [];
        foreach ($this->deckManager->getCardsInLocation(Location::HAND) as $handCard) {
            $handCardsById[intval($handCard['id'])] = $handCard;
        }

        $cards = [];
        foreach ($selectedCardIds as $cardId) {
            if (!isset($handCardsById[$cardId])) {
                throw new UserException(
                    \clienttranslate('You can only escape cards from your hand')
                );
            }
            $cards[] = $handCardsById[$cardId];
        }

        foreach ($cards as $card) {
            $cardId = intval($card['id']);
            $this->deckManager->insertCardOnExtremePosition(
                $cardId,
                Location::ESCAPED,
                true
            );
            $movedCard = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} escapes directly from the hand'),
                [
                    'card' => $movedCard,
                    'card_name' => $movedCard['card_name'],
                    'destination' => Location::ESCAPED,
                    'source' => Location::HAND,
                ]
            );
        }

        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
    }

    public function actStartBrainstorm(string $location): void
    {
        $this->checkAction('actStartBrainstorm');

        if (!in_array($location, $this->getAvailableDrawDecks(), true)) {
            throw new UserException(new NotificationMessage(
                \clienttranslate('You cannot brainstorm this deck'),
                ['location' => $location]
            ));
        }

        $brainstormLocation = $this->getBrainstormLocationForDeck($location);
        $cards = $this->deckManager->getCardsOnTop(3, $location);
        foreach ($cards as $position => $card) {
            $cardId = intval($card['id']);
            $this->clearDeckRevealIfCardLeaves($cardId, $location);
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

    private function startUnrememberChoice(): void
    {
        $cards = $this->deckManager->getCardsOnTop(
            3,
            Location::MEMORY
        );
        foreach ($cards as $position => $card) {
            $cardId = intval($card['id']);
            $this->deckManager->moveCard(
                $cardId,
                Location::UNREMEMBER,
                $position
            );
            $movedCard = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is recalled from Memory'),
                [
                    'card' => $movedCard,
                    'card_name' => $movedCard['card_name'],
                    'destination' => 'brainstorm',
                    'source' => Location::MEMORY,
                ]
            );
        }
    }

    public function argUnrememberChoice(): array
    {
        $this->getPendingUnrememberChoiceEvent();
        $cards = array_values($this->deckManager->getCardsInLocation(
            Location::UNREMEMBER,
            null,
            'location_arg'
        ));

        return [
            'cards' => $cards,
            'maximumCards' => min(2, count($cards)),
        ];
    }

    public function actConfirmUnremember(string $card_ids): void
    {
        $this->checkAction('actConfirmUnremember');
        $event = $this->getPendingUnrememberChoiceEvent();

        try {
            $submittedCardIds = json_decode(
                $card_ids,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new UserException(
                \clienttranslate('Invalid cards selected from Memory')
            );
        }
        if (!is_array($submittedCardIds)) {
            throw new UserException(
                \clienttranslate('Invalid cards selected from Memory')
            );
        }

        $selectedCardIds = array_map('intval', $submittedCardIds);
        if (
            count($selectedCardIds) > 2
            || count(array_unique($selectedCardIds))
                !== count($selectedCardIds)
        ) {
            throw new UserException(
                \clienttranslate('You may select up to two different cards')
            );
        }

        $cards = array_values($this->deckManager->getCardsInLocation(
            Location::UNREMEMBER,
            null,
            'location_arg'
        ));
        $cardsById = [];
        foreach ($cards as $card) {
            $cardsById[intval($card['id'])] = $card;
        }
        foreach ($selectedCardIds as $cardId) {
            if (!isset($cardsById[$cardId])) {
                throw new UserException(
                    \clienttranslate(
                        'You can only recover one of the displayed Memory cards'
                    )
                );
            }
        }

        $selectedLookup = array_fill_keys($selectedCardIds, true);
        foreach ($selectedCardIds as $cardId) {
            $this->deckManager->moveCard($cardId, Location::HAND);
            $movedCard = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is recovered from Memory'),
                [
                    'card' => $movedCard,
                    'card_name' => $movedCard['card_name'],
                    'destination' => Location::HAND,
                    'source' => 'brainstorm',
                ]
            );
        }

        $remainingCards = array_values(array_filter(
            $cards,
            static function (array $card) use ($selectedLookup): bool {
                return !isset($selectedLookup[intval($card['id'])]);
            }
        ));
        foreach (array_reverse($remainingCards) as $card) {
            $cardId = intval($card['id']);
            $this->deckManager->insertCardOnExtremePosition(
                $cardId,
                Location::MEMORY,
                true
            );
            $movedCard = $this->deckManager->getCard($cardId);
            $this->notify->all(
                'cardMoved',
                \clienttranslate('${card_name} is returned to Memory'),
                [
                    'card' => $movedCard,
                    'card_name' => $movedCard['card_name'],
                    'destination' => Location::MEMORY,
                    'source' => 'brainstorm',
                ]
            );
        }

        $this->popEvent($event['id']);
        $this->gamestate->nextState(Transition::DISPATCH_EVENTS);
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
        if (intval($card['type']) == CardType::PROTAGONIST && $card['location'] == Location::HAND && $this->getProtagonistInPlay() === null) {
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
        $this->clearDeckRevealIfCardLeaves(
            intval($cardPicked['id']),
            $location
        );

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
        $win = $this->checkWin();
        $loss = $this->isLossReached(); // WINLOSS check loss condition

        // Go to following game state
        if ($loss) {
            $this->notify->all('gameLoss', \clienttranslate("You lost the game"));
            $this->gamestate->nextState(Transition::GAME_END);
        } else if ($win) {
            $this->bga->playerScore->setAll(self::WIN_SCORE);
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
        $result['ruralDeckTop'] = $this->getRevealedDeckTop(Location::RURAL);
        $result['urbanDeckTop'] = $this->getRevealedDeckTop(Location::URBAN);
        $brainstormLocation = $this->getCurrentBrainstormLocation();
        $displayedChoiceLocation = $this->deckManager
            ->countCardInLocation(Location::UNREMEMBER) > 0
                ? Location::UNREMEMBER
                : $brainstormLocation;
        $result['brainstorm'] = $displayedChoiceLocation === null
            ? []
            : $this->deckManager->getCardsInLocation(
                $displayedChoiceLocation,
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
        $this->setGameStateInitialValue('revealedRuralCardId', 0);
        $this->setGameStateInitialValue('revealedUrbanCardId', 0);
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
