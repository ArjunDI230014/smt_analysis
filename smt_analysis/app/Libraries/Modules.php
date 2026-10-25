<?php

namespace App\Libraries;

use DomainException;

/** Module context comes from the URL so separate browser tabs cannot change each other's workspace. */
final class Modules
{
    public const SHOPEE_FOOD = 'shopee-food';
    public const MARKETPLACE = 'marketplace';

    public static function all(): array
    {
        return [self::SHOPEE_FOOD => 'Shopee Food', self::MARKETPLACE => 'Marketplace'];
    }

    public static function validate(string $module): string
    {
        if (!array_key_exists($module, self::all())) {
            throw new DomainException('Choose a valid module.');
        }
        return $module;
    }

    public static function current(): string
    {
        $path = trim(uri_string(), '/');
        return explode('/', $path, 2)[0] === self::MARKETPLACE ? self::MARKETPLACE : self::SHOPEE_FOOD;
    }

    public static function label(?string $module = null): string
    {
        return self::all()[self::validate($module ?? self::current())];
    }

    public static function url(string $path = '', ?string $module = null): string
    {
        $module = self::validate($module ?? self::current());
        $path = ltrim($path, '/');
        return site_url(($module === self::MARKETPLACE ? 'marketplace/' : '') . $path);
    }
}
