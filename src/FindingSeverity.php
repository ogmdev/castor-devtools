<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

enum FindingSeverity
{
    case Ok;
    case Warning;
    case Blocking;
    case Unknown;

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Warning => 'to watch',
            self::Blocking => 'blocking',
            self::Unknown => 'unknown',
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Warning => 'to watch',
            self::Blocking => 'to unblock',
            self::Unknown => 'unknown',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Warning => 'check',
            self::Blocking => 'BLOCKING',
            self::Unknown => 'unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'green',
            self::Warning => 'yellow',
            self::Blocking => 'red;options=bold',
            self::Unknown => 'default',
        };
    }

    public function needsAction(): bool
    {
        return $this !== self::Ok;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Blocking => 0,
            self::Warning => 1,
            self::Unknown => 2,
            self::Ok => 3,
        };
    }
}
