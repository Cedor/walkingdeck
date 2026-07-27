<?php

declare(strict_types=1);

namespace Bga\Games\TheWalkingDeck;

class TWDEventStack
{
    private Game $game;

    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    public function pushEvent(string $type, array $parameters = []): void
    {
        $allowedTypes = [
            TWDEventType\Consequence,
            TWDEventType\DrawCard,
            TWDEventType\SpecialDraw,
            TWDEventType\AdditionalDraw,
            TWDEventType\PlayCard,
            TWDEventType\NextState,
            TWDEventType\ForcePass,
        ];

        if (!in_array($type, $allowedTypes, true)) {
            throw new \BgaUserException("Unknown event type: $type");
        }

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

        return [
            'id' => intval($event['event_id']),
            'type' => $event['event_type'],
            'parameters' => $event['event_parameters'] === null
                ? []
                : json_decode($event['event_parameters'], true, 512, JSON_THROW_ON_ERROR),
        ];
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
        return $this->getCurrentEvent() === null;
    }
}
