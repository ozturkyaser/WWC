<?php

namespace App\Services;

class HmacSigner
{
    public static function sign(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $secret
    ): string {
        $payload = strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$body;

        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
        string $secret
    ): bool {
        $expected = self::sign($method, $path, $timestamp, $nonce, $body, $secret);

        return hash_equals($expected, $signature);
    }

    public static function isTimestampFresh(string $timestamp, int $windowSeconds = 120): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $windowSeconds;
    }
}
