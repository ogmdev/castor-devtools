<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use Castor\Context;

use function Castor\context;
use function Castor\io;

final readonly class InteractiveContext
{
    public static function whenSupported(?Context $context = null): Context
    {
        $context ??= context();

        return $context->supportsInteraction() ? $context->toInteractive()->withAllowFailure(false) : $context;
    }

    public static function required(): Context
    {
        return context()->toInteractive();
    }

    public static function confirm(string $question, bool $default = false): bool
    {
        return context()->supportsInteraction() && io()->confirm($question, $default);
    }
}
