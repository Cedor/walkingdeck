<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck;

/**
 * Translation markers for card names stored in the database.
 *
 * The client translates card names dynamically according to translate_name.
 * These static markers let the BGA translation extractor discover them.
 */
final class CardNameTranslations
{
    public static function getAll(): array
    {
        return [
            \clienttranslate('Wolf Trap'),
            \clienttranslate('Clown'),
            \clienttranslate('Ellie and Joel'),
            \clienttranslate('Brigade'),
            \clienttranslate('Bonfire'),
            \clienttranslate('Horse'),
            \clienttranslate('RV'),
            \clienttranslate('Cellar'),
            \clienttranslate('Teddy Bear'),
            \clienttranslate('Voodoo'),
            \clienttranslate('Mutt'),
            \clienttranslate('Grenade'),
            \clienttranslate('Musicians'),
            \clienttranslate('Site Manager'),
            \clienttranslate('Horde'),
            \clienttranslate('Butler'),
            \clienttranslate('Canned food'),
            \clienttranslate('Warehouse'),
            \clienttranslate('Medical alcohol'),
            \clienttranslate('Map'),
            \clienttranslate('The reaper'),
            \clienttranslate('Controller'),
            \clienttranslate('LGS'),
            \clienttranslate('Teacher'),
        ];
    }
}
