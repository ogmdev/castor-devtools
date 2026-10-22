<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

final class CurrentCheckout
{
    private static ?Checkout $checkout = null;

    public static function use(Checkout $checkout): void
    {
        self::$checkout = $checkout;
    }

    public static function get(): Checkout
    {
        return self::$checkout ??= Checkout::main((string) getcwd());
    }

    public static function reset(): void
    {
        self::$checkout = null;
    }
}
