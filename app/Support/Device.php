<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Identifiant d'appareil : un cookie posé une fois (site) ou l'en-tête X-Device-Id (application).
 * Sert à repérer plusieurs comptes utilisés depuis le même téléphone.
 */
class Device
{
    public const COOKIE = 'tk_dev';

    public static function id(?Request $request = null): ?string
    {
        $request ??= request();
        $id = (string) ($request->header('X-Device-Id') ?: $request->cookie(self::COOKIE) ?: $request->attributes->get(self::COOKIE));

        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id) ? $id : null;
    }

    public static function fresh(): string
    {
        return Str::random(32);
    }
}
