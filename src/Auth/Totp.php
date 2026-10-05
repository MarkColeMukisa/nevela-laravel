<?php

namespace Nevela\Laravel\Auth;

/**
 * Time-based one-time passwords (RFC 6238), as every authenticator app implements them:
 * SHA-1, six digits, a new code every 30 seconds. Pure PHP, no dependencies.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** A new secret, as the base32 text an authenticator app is given. 160 bits, as the RFC recommends. */
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    /** The code for a moment in time. */
    public static function code(string $secret, ?int $at = null): string
    {
        $counter = intdiv($at ?? time(), self::PERIOD);
        $hash = hash_hmac('sha1', pack('J', $counter), self::bytes($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % (10 ** self::DIGITS);

        return str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a code is right, now or one step either side: clocks drift, and a code typed
     * as it changes should still work.
     */
    public static function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $at ??= time();
        foreach ([0, -1, 1] as $step) {
            if (hash_equals(self::code($secret, $at + $step * self::PERIOD), $code)) {
                return true;
            }
        }

        return false;
    }

    /** What an authenticator app scans: the secret, and the names it files it under. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $text;
    }

    private static function bytes(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
