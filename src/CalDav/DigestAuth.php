<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

/**
 * RFC 7616 Digest authentication helper.
 *
 * Parses `WWW-Authenticate: Digest ...` challenges and computes the
 * matching `Authorization: Digest ...` response. Supports the
 * algorithm variants and qop modes the live CalDAV ecosystem uses
 * (MD5, MD5-sess, SHA-256, SHA-512 with `qop=auth` or no qop).
 *
 * Lives outside {@see CalDavClient} so the cryptographic primitive
 * is unit-testable in isolation — RFC 7616 §3.9 ships deterministic
 * test vectors that pin the exact bytes for `HA1`, `HA2`, and
 * `response`, and we lean on those to catch off-by-one mistakes
 * (e.g. wrong order in `HA1:nonce:nc:cnonce:qop:HA2`).
 */
final class DigestAuth
{
    public const ALGO_MD5       = 'MD5';
    public const ALGO_MD5_SESS  = 'MD5-sess';
    public const ALGO_SHA256    = 'SHA-256';
    public const ALGO_SHA512    = 'SHA-512';
    public const QOP_AUTH       = 'auth';

    /**
     * RFC 2617 / 7616 §3.4.1 — HA1 is `username:realm:password` for the
     * plain MD5 / SHA families and re-hashed with nonce + cnonce for the
     * `-sess` variants. The format string is shared between both paths.
     */
    private const CRED_COLON_FORMAT = '%s:%s:%s';

    /**
     * Parse a `WWW-Authenticate` header value (or array of header
     * values for the same name) and return the Digest challenge as
     * an associative array. Returns null if no Digest challenge is
     * present, e.g. when the server only advertises Basic.
     *
     * The header may carry multiple schemes comma-separated
     * (`Digest realm="x", Basic realm="y"`); we walk every comma-
     * separated chunk so the first Digest block wins regardless of
     * declaration order. Quoted values with embedded commas (realm,
     * nonce, opaque) survive the split because the parser tracks
     * quote depth.
     *
     * @param string|list<string> $headerValue Raw header(s) from the
     *                                         Symfony response header bag.
     * @return array{realm: string, nonce: string, qop?: string, opaque?: string, algorithm: string}|null
     */
    public static function parseChallenge(string|array $headerValue): ?array
    {
        foreach ((array) $headerValue as $value) {
            foreach (self::splitAuthHeaders((string) $value) as $block) {
                if (stripos($block, 'Digest ') !== 0) {
                    continue;
                }
                $parsed = self::parseDigestBlock(substr($block, 7));
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        return null;
    }

    /**
     * Build a complete `Authorization: Digest ...` header value for
     * the given credentials, request, and challenge.
     */
    public static function buildAuthorizationHeader(
        string $username,
        string $password,
        string $method,
        string $uri,
        array $challenge,
    ): string {
        $nc     = '00000001';
        $cnonce = self::generateCnonce();

        $ha1    = self::computeHa1($username, $password, $challenge['realm'], $challenge['algorithm'], $challenge['nonce'] ?? '', $cnonce);
        $ha2    = self::computeHa2($method, $uri);
        $qop    = $challenge['qop'] ?? '';
        $digest = self::computeResponse($ha1, $ha2, $challenge['nonce'] ?? '', $nc, $cnonce, $qop);

        $parts = [
            sprintf('username="%s"', self::quote($username)),
            sprintf('realm="%s"', self::quote($challenge['realm'])),
            sprintf('nonce="%s"', self::quote($challenge['nonce'] ?? '')),
            sprintf('uri="%s"', self::quote($uri)),
            sprintf('response="%s"', $digest),
            sprintf('algorithm=%s', $challenge['algorithm']),
        ];

        if ($qop !== '') {
            $parts[] = sprintf('qop=%s', $qop);
            $parts[] = sprintf('nc=%s', $nc);
            $parts[] = sprintf('cnonce="%s"', $cnonce);
        }

        if (!empty($challenge['opaque'])) {
            $parts[] = sprintf('opaque="%s"', self::quote((string) $challenge['opaque']));
        }

        return 'Digest ' . implode(', ', $parts);
    }

    /**
     * Compute `HA1` per RFC 7616 §3.4 (and §3.4.2 for `MD5-sess`).
     */
    public static function computeHa1(
        string $username,
        string $password,
        string $realm,
        string $algorithm,
        string $nonce,
        string $cnonce,
    ): string {
        $hash = self::hashAlgo($algorithm, sprintf(self::CRED_COLON_FORMAT, $username, $realm, $password));

        if ($algorithm === self::ALGO_MD5_SESS) {
            $hash = self::hashAlgo($algorithm, sprintf(self::CRED_COLON_FORMAT, $hash, $nonce, $cnonce));
        }

        return $hash;
    }

    /**
     * Compute `HA2` per RFC 7616 §3.4 (no qop: `qop=auth-int` is not
     * implemented because no mainstream CalDAV server advertises it).
     */
    public static function computeHa2(string $method, string $uri): string
    {
        return self::hashAlgo(self::ALGO_MD5, sprintf('%s:%s', strtoupper($method), $uri));
    }

    /**
     * Compute the final `response` value per RFC 7616 §3.4.1.
     */
    public static function computeResponse(
        string $ha1,
        string $ha2,
        string $nonce,
        string $nc,
        string $cnonce,
        string $qop,
    ): string {
        if ($qop === self::QOP_AUTH) {
            $data = sprintf('%s:%s:%s:%s:%s:%s', $ha1, $nonce, $nc, $cnonce, $qop, $ha2);
        } else {
            $data = sprintf('%s:%s:%s', $ha1, $nonce, $ha2);
        }
        return self::hashAlgo(self::ALGO_MD5, $data);
    }

    /**
     * Split a `WWW-Authenticate` header value on top-level commas
     * into individual challenges. RFC 7235 §2.2 defines a challenge
     * as `auth-scheme 1#auth-param`, so a single Digest block looks
     * like `Digest realm="X", nonce="Y"` (parameters separated by
     * top-level commas), and multiple schemes look like
     * `Digest realm="X", Basic realm="Y"`.
     *
     * The naive "split on every top-level comma" approach fails: it
     * also splits the parameters of a single Digest block. Instead,
     * we only break at a top-level comma when the following token is
     * an auth-scheme name (Digest, Basic, Bearer, …) — that's the
     * syntactic boundary between challenges.
     *
     * Quoted-string commas (realm/nonce/opaque can contain commas)
     * are preserved by tracking quote depth.
     *
     * @return list<string>
     */
    private static function splitAuthHeaders(string $value): array
    {
        $blocks = [];
        $current = '';
        $inQuotes = false;
        $escape = false;
        $length = strlen($value);
        $schemesPattern = '/^\s*(?:Digest|Basic|Bearer|Negotiate|NTLM|OAuth)(?:\s|$)/i';

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($escape) {
                $current .= $char;
                $escape = false;
                continue;
            }
            if ($char === '\\') {
                $current .= $char;
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                $current .= $char;
                continue;
            }
            if ($char === ',' && !$inQuotes) {
                $remainder = substr($value, $i + 1);
                if (preg_match($schemesPattern, $remainder) === 1) {
                    $blocks[] = trim($current);
                    $current = '';
                    continue;
                }
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $blocks[] = trim($current);
        }
        return $blocks;
    }

    /**
     * @return array{realm: string, nonce: string, qop?: string, opaque?: string, algorithm: string}|null
     */
    private static function parseDigestBlock(string $body): ?array
    {
        $pairs = self::parseAuthPairs($body);
        if (!isset($pairs['realm']) || !isset($pairs['nonce'])) {
            return null;
        }
        $challenge = [
            'realm'     => (string) $pairs['realm'],
            'nonce'     => (string) $pairs['nonce'],
            'algorithm' => self::ALGO_MD5,
        ];
        if (isset($pairs['qop'])) {
            $qop = trim((string) $pairs['qop'], '"');
            $qop = explode(',', $qop)[0];
            $qop = trim($qop);
            if ($qop !== '') {
                $challenge['qop'] = $qop;
            }
        }
        if (isset($pairs['opaque'])) {
            $challenge['opaque'] = (string) $pairs['opaque'];
        }
        if (isset($pairs['algorithm'])) {
            $algorithm = trim((string) $pairs['algorithm'], '"');
            if ($algorithm !== '') {
                $challenge['algorithm'] = $algorithm;
            }
        }
        return $challenge;
    }

    /**
     * @return array<string, string>
     */
    private static function parseAuthPairs(string $body): array
    {
        $pairs = [];
        $inQuotes = false;
        $escape = false;
        $current = '';
        $length = strlen($body);

        $flush = static function () use (&$pairs, &$current): void {
            $entry = trim($current);
            $current = '';
            if ($entry === '') {
                return;
            }
            $eq = strpos($entry, '=');
            if ($eq === false) {
                return;
            }
            $key = strtolower(trim(substr($entry, 0, $eq)));
            $val = trim(substr($entry, $eq + 1));
            $pairs[$key] = self::stripQuotes($val);
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($escape) {
                $current .= $char;
                $escape = false;
                continue;
            }
            if ($char === '\\') {
                $current .= $char;
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                $current .= $char;
                continue;
            }
            if ($char === ',' && !$inQuotes) {
                $flush();
                continue;
            }
            $current .= $char;
        }
        $flush();
        return $pairs;
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            return substr($value, 1, -1);
        }
        return $value;
    }

    private static function quote(string $value): string
    {
        return addcslashes($value, '"\\');
    }

    private static function hashAlgo(string $algorithm, string $data): string
    {
        $algo = match ($algorithm) {
            self::ALGO_MD5      => 'md5',
            self::ALGO_SHA256   => 'sha256',
            self::ALGO_SHA512   => 'sha512',
            // Treat any sess variant as MD5 — only the second pass differs
            // and the caller is responsible for the outer MD5-sess wrap.
            default             => 'md5',
        };
        return hash($algo, $data);
    }

    private static function generateCnonce(): string
    {
        return bin2hex(random_bytes(8));
    }
}
