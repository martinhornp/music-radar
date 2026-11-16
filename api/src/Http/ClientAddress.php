<?php

declare(strict_types=1);

namespace MusicRadar\Http;

/**
 * The client's address for the failed-login limit - never taken blindly from a
 * header a client can set. It is REMOTE_ADDR, the address the web server sees;
 * only when that address belongs to a proxy we trust (config
 * auth.trusted_proxies, CIDR ranges, empty by default) is X-Forwarded-For
 * used - its RIGHT-most address that is not one of those proxies (the
 * left-most entries are whatever the client sent).
 */
final class ClientAddress
{
    /** @param list<string> $trustedProxies CIDR ranges ("10.0.0.0/8") or single addresses */
    public static function of(Request $request, array $trustedProxies): string
    {
        $remote = $request->header('remote-addr');
        if ($remote === '' || !self::inAny($remote, $trustedProxies)) {
            return $remote ?: 'unknown';
        }
        $chain = array_reverse(array_filter(array_map('trim', explode(',', $request->header('x-forwarded-for')))));
        foreach ($chain as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                break; // garbage: stop at the proxy's own address
            }
            if (!self::inAny($address, $trustedProxies)) {
                return $address;
            }
        }
        return $remote;
    }

    /** @param list<string> $ranges */
    public static function inAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$net, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        $a = @inet_pton($address);
        $n = @inet_pton($net);
        if ($a === false || $n === false || strlen($a) !== strlen($n)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($n, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        return $rest === 0 || ((ord($a[$bytes]) ^ ord($n[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }
}
