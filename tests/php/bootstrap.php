<?php

declare(strict_types=1);

namespace {
    if (!function_exists('clienttranslate')) {
        function clienttranslate(string $message): string
        {
            return $message;
        }
    }
}

namespace Bga\GameFramework {
    class NotificationMessage
    {
        private string $message;

        public function __construct(string $message, array $arguments = [])
        {
            $this->message = $message;
        }

        public function __toString(): string
        {
            return $this->message;
        }
    }

    class SystemException extends \RuntimeException
    {
    }

    class UserException extends \RuntimeException
    {
        public function __construct($message = '', int $code = 0, ?\Throwable $previous = null)
        {
            parent::__construct((string) $message, $code, $previous);
        }
    }

    class Table
    {
        public $bga;
        public $gamestate;
        public $notify;
        public array $extraTimePlayerIds = [];
        private array $testStateValues = [];

        public function __construct()
        {
        }

        public function DbQuery(string $query): void
        {
        }

        public function escapeStringForDB(string $value): string
        {
            return addslashes($value);
        }

        public function getObjectFromDB(string $query)
        {
            return null;
        }

        public function getObjectListFromDB(string $query): array
        {
            return [];
        }

        public function getUniqueValueFromDB(string $query)
        {
            return 0;
        }

        public function initGameStateLabels(array $labels): void
        {
        }

        public function setGameStateInitialValue(string $name, int $value): void
        {
            $this->testStateValues[$name] = $value;
        }

        public function setGameStateValue(string $name, int $value): void
        {
            $this->testStateValues[$name] = $value;
        }

        public function getGameStateValue(string $name): int
        {
            return $this->testStateValues[$name] ?? 0;
        }

        public function checkAction(string $action): void
        {
        }

        public function getActivePlayerId(): string
        {
            return '1';
        }

        public function giveExtraTime(int $playerId, ?int $specificTime = null): void
        {
            $this->extraTimePlayerIds[] = $playerId;
        }
    }
}

namespace Bga\GameFramework\Components {
    class Deck
    {
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}
