<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck;

use Bga\Games\TheWalkingDeck\Constants\EventType;
use Bga\GameFramework\SystemException;

final class TWDEventStack
{
    private const SUPPORTED_TYPES = [
        EventType::CONSEQUENCE,
        EventType::BITE_CHOICE,
        EventType::HEAL_CHOICE,
        EventType::BURY_CHARACTER_CHOICE,
        EventType::DISASTER_CHOICE,
        EventType::WOLF_TRAP_CHOICE,
        EventType::RECOVER_CHOICE,
        EventType::DRAW_CARD,
        EventType::SPECIAL_DRAW,
        EventType::ADDITIONAL_DRAW,
        EventType::PLAY_CARD,
        EventType::NEXT_STATE,
    ];

    private Game $game;

    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    public function pushEvent(string $type, array $parameters = []): void
    {
        $this->assertSupportedType($type);

        $escapedType = $this->game->escapeStringForDB($type);
        $encodedParameters = json_encode($parameters, JSON_THROW_ON_ERROR);
        $escapedParameters = $this->game->escapeStringForDB($encodedParameters);

        $this->game->DbQuery(
            "INSERT INTO `twd_event_stack` (`event_type`, `event_parameters`)
             VALUES ('$escapedType', '$escapedParameters')"
        );
    }

    public function getCurrentEvent(): ?array
    {
        $event = $this->game->getObjectFromDB(
            "SELECT `event_id`, `event_type`, `event_parameters`
             FROM `twd_event_stack`
             ORDER BY `event_id` DESC
             LIMIT 1"
        );

        if (!$event) {
            return null;
        }

        $type = strval($event['event_type']);
        $this->assertSupportedType($type);

        $parameters = [];
        if ($event['event_parameters'] !== null) {
            $decodedParameters = json_decode(
                $event['event_parameters'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($decodedParameters)) {
                throw new SystemException(
                    "Invalid parameters for event {$event['event_id']}"
                );
            }
            $parameters = $decodedParameters;
        }

        return [
            'id' => intval($event['event_id']),
            'type' => $type,
            'parameters' => $parameters,
        ];
    }

    public function updateEventParameters(int $eventId, array $parameters): void
    {
        if ($eventId < 1) {
            throw new SystemException('Invalid event id');
        }

        $encodedParameters = json_encode($parameters, JSON_THROW_ON_ERROR);
        $escapedParameters = $this->game->escapeStringForDB($encodedParameters);
        $this->game->DbQuery(
            "UPDATE `twd_event_stack`
             SET `event_parameters` = '$escapedParameters'
             WHERE `event_id` = $eventId"
        );
    }

    public function popEvent(): ?array
    {
        $event = $this->getCurrentEvent();
        if ($event === null) {
            return null;
        }

        $eventId = $event['id'];
        $this->game->DbQuery(
            "DELETE FROM `twd_event_stack` WHERE `event_id` = $eventId"
        );

        return $event;
    }

    public function isEmpty(): bool
    {
        return intval(
            $this->game->getUniqueValueFromDB(
                "SELECT COUNT(*) FROM `twd_event_stack`"
            )
        ) === 0;
    }

    private function assertSupportedType(string $type): void
    {
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new SystemException("Unknown event type: $type");
        }
    }
}
