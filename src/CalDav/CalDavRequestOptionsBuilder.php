<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

/**
 * Small request-prep helpers shared across every CalDAV dispatch
 * method. Pure: no I/O, no DI. Lifted out of
 * {@see CalDavOperationHelpers} to keep that class under Sonar's
 * 20-method ceiling (S1448).
 */
final class CalDavRequestOptionsBuilder
{
    /**
     * Build the Symfony HttpClient options array shared by every
     * dispatch method. Centralising it keeps `auth_basic`, the
     * timeout, and the `auth_method` plugin-internal key in lockstep —
     * the digest-retry path in {@see CalDavClient} reads `auth_method`
     * to decide whether to send Basic preemptively or wait for a
     * 401 challenge.
     *
     * @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function buildRequestOptions(
        array $config,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
    ): array {
        $options = [
            'headers'     => $headers,
            'auth_basic'  => [$config['username'], $config['password']],
            'timeout'     => $timeoutSeconds,
            'auth_method' => $config['authMethod'],
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }
        return $options;
    }

    /**
     * Expand a bare date `YYYY-MM-DD` into a full ISO-8601 timestamp.
     * Start dates expand to T00:00:00 (midnight) and end dates to
     * T23:59:59 so a caller passing `start_date=YYYY-MM-DD,
     * end_date=YYYY-MM-DD` covers the whole day without the server
     * rejecting it as "no time component". Strings that already
     * contain a time component pass through unchanged.
     */
    public function expandDateOnly(string $dateStr, bool $isEnd): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) === 1) {
            return $dateStr . ($isEnd ? 'T23:59:59' : 'T00:00:00');
        }
        return $dateStr;
    }
}
