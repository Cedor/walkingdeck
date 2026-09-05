<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck;

/**
 * Translation markers for card texts stored as JSON in the database.
 *
 * The client translates these values dynamically with _(definition.text).
 * Keeping every translatable literal here lets the BGA translation extractor
 * discover it. Texts made exclusively of placeholders do not need translation.
 */
final class CardTextTranslations
{
    public static function getAll(): array
    {
        return [
            \clienttranslate('${disaster} becomes: draw two disasters and pick one'),
            \clienttranslate('${disaster} and add ${weakness1} and ${weakness2}'),
            \clienttranslate('${disaster} and ignore ${hunger}'),
            \clienttranslate('${disaster} and ignore ${stress}'),
            \clienttranslate('${heal} up to ${number} characters'),
            \clienttranslate('${heal} up to ${number} characters with ${break}'),
            \clienttranslate('${heal} up to ${number} characters with ${hunger}/${stress}'),
            \clienttranslate('${heal} up to ${number} characters with ${stress}'),
            \clienttranslate('${number} peripeteia in the graveyard'),
            \clienttranslate('Add the 2 empty disasters to the bag, then bury this peripeteia'),
            \clienttranslate('Anytime : consume an available ressource to reveal the top card of each deck'),
            \clienttranslate('Avoid a zombie from your hand'),
            \clienttranslate('Avoid a zombie from your hand or bury this peripeteia'),
            \clienttranslate('Avoid a zombie from your hand or the top card of Memory'),
            \clienttranslate('Avoid a zombie from your hand, then draw a disaster. If it is ${disaster1}/${disaster2}, bury this peripeteia'),
            \clienttranslate('Avoid the top 2 cards of one deck without applying their effects'),
            \clienttranslate('Avoid the top card of both decks, all characters avoided will be buried'),
            \clienttranslate('Avoid the top card of Memory without applying its effects'),
            \clienttranslate('Bury a character from your hand or bury this peripeteia'),
            \clienttranslate('Bury the top peripeteia of one deck'),
            \clienttranslate('Bury this peripeteia'),
            \clienttranslate('Definitively remove the two disasters ${break}'),
            \clienttranslate('Draw ${number} peripeteia(s)'),
            \clienttranslate('Draw ${number} peripeteia(s), avoid up to 2 peripeteias from your hand, then bury this peripeteia'),
            \clienttranslate('Draw ${number} peripeteia(s), then bury this peripeteia'),
            \clienttranslate('Draw 2 disasters, remove one from the game and return the other to the bag'),
            \clienttranslate('Immediately memorise this peripeteia, then draw a new one'),
            \clienttranslate('Look at the top 3 cards of Memory. Return up to 2 of them to your hand, then bury this peripeteia'),
            \clienttranslate('Memorise 2 peripeteias from one deck'),
            \clienttranslate('Move the top peripeteia from the graveyard to the escaped zone'),
            \clienttranslate('Move the top peripeteia from the graveyard to the escaped zone, then draw a disaster'),
            \clienttranslate('No effect'),
            \clienttranslate('Once a game : Tap this Protagonist to prevent one damage done on one character'),
            \clienttranslate('Pick a card from the Escaped area to move it to your hand'),
            \clienttranslate('Reorder the top 3 peripeteias of one deck, then put them back visible'),
            \clienttranslate('Reveal the top peripeteia of each deck'),
            \clienttranslate('Send up to 2 peripeteias from your hand to the bottom of memory, then bury this peripeteia'),
            \clienttranslate('Shuffle all peripeteias and separate them in 2 decks'),
            \clienttranslate('something something something'),
            \clienttranslate('When the third damaged is applied to Robert, remove him from the game'),
            \clienttranslate('When you bury a peripeteia : sacrifice a ressource, and gain the following permanent effect :'),
            \clienttranslate('you can keep two peripeteia in hand'),
        ];
    }
}
