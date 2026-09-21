<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * HTTP transport layer for CalDAV: timeout resolution, URI normalization,
 * ETag normalization, request dispatch, and HTTP error logging. Exposes a
 * single `request()` method so the tool class can focus on CalDAV semantics
 * (REPORT/PUT/GET/DELETE) rather than transport details.
 *
 * Auth scheme handling: `auth_method` in `$options` selects the wire
 * format. `'auto'` (default) sends Basic preemptively — the common case
 * for Nextcloud/Baikal/Radicale/iCloud — and falls back to Digest on a
 * 401 Digest challenge (all-inkl, Cyrus, Kerio). `'basic'` and
 * `'digest'` pin to a single scheme.
 */
final class CalDavClient
{
    public const AUTH_AUTO   = 'auto';
    public const AUTH_BASIC  = 'basic';
    public const AUTH_DIGEST = 'digest';

    private const DEFAULT_HTTP_TIMEOUT = 30;
    private const VALID_AUTH_METHODS   = [self::AUTH_AUTO, self::AUTH_BASIC, self::AUTH_DIGEST];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $options Symfony HttpClient options (headers, body, auth_basic, timeout, …)
     *                                     plus the plugin-specific `auth_method` key.
     */
    public function request(string $method, string $url, array $options): ResponseInterface
    {
        $authMethod  = $this->resolveAuthMethod($options);
        $credentials = $this->extractCredentials($options);

        $response = $this->dispatch($method, $url, $options, $authMethod);

        if ($response->getStatusCode() !== 401) {
            return $response;
        }
        if ($credentials === null) {
            return $response;
        }
        if ($authMethod === self::AUTH_BASIC) {
            return $response;
        }

        $challenge = DigestAuth::parseChallenge($response->getHeaders(false)['www-authenticate'] ?? null);
        if ($challenge === null) {
            return $response;
        }

        $this->logger?->info('CalDavCalendarTool: retrying with Digest auth', [
            'method' => $method,
            'url'    => $url,
            'realm'  => $challenge['realm'],
        ]);

        return $this->dispatchWithDigest($method, $url, $options, $credentials, $challenge);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dispatch(string $method, string $url, array $options, string $authMethod): ResponseInterface
    {
        $wireOptions = $this->prepareFirstAttempt($options, $authMethod);

        $this->logHttpRequest($method, $url, $wireOptions);
        try {
            $response = $this->httpClient->request($method, $url, $wireOptions);
        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', [
                'method'    => $method,
                'url'       => $url,
                'exception' => $e,
            ]);
            throw new CalDavException("CalDAV {$method} {$url} failed: {$e->getMessage()}", 0, $e);
        }
        $this->logHttpResponse($method, $url, $response->getStatusCode(), $response->getHeaders(false));
        return $response;
    }

    /**
     * @param array{username: string, password: string} $credentials
     * @param array{realm: string, nonce: string, qop?: string, opaque?: string, algorithm: string} $challenge
     * @param array<string, mixed> $options
     */
    private function dispatchWithDigest(
        string $method,
        string $url,
        array $options,
        array $credentials,
        array $challenge,
    ): ResponseInterface {
        $authHeader = DigestAuth::buildAuthorizationHeader(
            $credentials['username'],
            $credentials['password'],
            $method,
            $this->extractRequestUri($url),
            $challenge,
        );

        $wireOptions = $this->prepareRetry($options, $authHeader);
        $this->logHttpRequest($method, $url, $wireOptions);

        try {
            $response = $this->httpClient->request($method, $url, $wireOptions);
        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception (digest retry)', [
                'method'    => $method,
                'url'       => $url,
                'exception' => $e,
            ]);
            throw new CalDavException("CalDAV {$method} {$url} failed: {$e->getMessage()}", 0, $e);
        }
        $this->logHttpResponse($method, $url, $response->getStatusCode(), $response->getHeaders(false));
        return $response;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveAuthMethod(array $options): string
    {
        $method = $options['auth_method'] ?? self::AUTH_AUTO;
        if (!in_array($method, self::VALID_AUTH_METHODS, true)) {
            $method = self::AUTH_AUTO;
        }
        return $method;
    }

    /**
     * Pull the credential pair out of `auth_basic` (or `auth_digest`)
     * for use on a Digest retry. The basic-only path leaves them in
     * place so Symfony can still attach the Authorization header.
     *
     * @param  array<string, mixed> $options
     * @return array{username: string, password: string}|null
     */
    private function extractCredentials(array $options): ?array
    {
        $basic = $options['auth_basic'] ?? null;
        if (is_array($basic) && count($basic) === 2) {
            return ['username' => (string) $basic[0], 'password' => (string) $basic[1]];
        }
        $digest = $options['auth_digest'] ?? null;
        if (is_array($digest) && count($digest) === 2) {
            return ['username' => (string) $digest[0], 'password' => (string) $digest[1]];
        }
        return null;
    }

    /**
     * Strip plugin-internal options before handing the bag to Symfony
     * HttpClient, and reshape auth according to the chosen mode:
     *
     * - 'auto' / 'basic': keep `auth_basic` so the request goes out
     *   preemptive (zero round-trips on Basic-only servers).
     * - 'digest': strip `auth_basic`; the first request goes out
     *   anonymous so the server can issue its 401 Digest challenge.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function prepareFirstAttempt(array $options, string $authMethod): array
    {
        unset($options['auth_method']);
        if ($authMethod === self::AUTH_DIGEST) {
            unset($options['auth_basic']);
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function prepareRetry(array $options, string $authorizationHeader): array
    {
        unset($options['auth_method'], $options['auth_basic']);
        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers['Authorization'] = $authorizationHeader;
        $options['headers'] = $headers;
        return $options;
    }

    /**
     * Strip the scheme + authority from a fully-qualified URL so the
     * Digest `uri` parameter matches the request-target RFC 7230
     * servers check against. Falls back to the raw URL when no scheme
     * is present (the caller is already responsible for passing an
     * absolute URL; CalDavOperationHelpers guarantees that).
     */
    private function extractRequestUri(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $settings Tool settings
     */
    public function effectiveTimeout(array $settings): int
    {
        $setting = (int) ($settings['http_timeout'] ?? 0);
        if ($setting > 0) {
            return $setting;
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : self::DEFAULT_HTTP_TIMEOUT;
    }

    /**
     * Resolve a CalDAV event URI returned by the server against the configured base URL.
     */
    public function resolveEventUri(string $eventUri, string $baseUrl): string
    {
        if ($this->isAbsoluteHttpUrl($eventUri) || $baseUrl === '') {
            return $eventUri;
        }

        $origin = $this->extractOrigin($baseUrl);
        if ($origin === null) {
            return $eventUri;
        }

        return $origin . '/' . ltrim($eventUri, '/');
    }

    private function isAbsoluteHttpUrl(string $uri): bool
    {
        return str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://');
    }

    private function extractOrigin(string $baseUrl): ?string
    {
        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin;
    }

    /**
     * Normalize an ETag value to the RFC 7232 quoted form.
     */
    public function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if ($etag === '') {
            return '';
        }
        if (str_starts_with($etag, 'W/')) {
            $inner = substr($etag, 2);
            if (!str_starts_with($inner, '"')) {
                $inner = '"' . $inner;
            }
            if (!str_ends_with($inner, '"')) {
                $inner = $inner . '"';
            }
            return 'W/' . $inner;
        }
        if (!str_starts_with($etag, '"')) {
            $etag = '"' . $etag;
        }
        if (!str_ends_with($etag, '"')) {
            $etag = $etag . '"';
        }
        return $etag;
    }

    /**
     * Decide whether a caller-supplied ETag should be trusted, or whether
     * the edit flow should fetch a fresh one from the server first.
     *
     * O3 placeholder detection: agents frequently echo back obvious
     * non-values ("initial", "none", "todo", "unknown") that pass the
     * syntactic RFC 7232 check after `normalizeEtag()` but are guaranteed
     * 412s on the wire. Reject anything that doesn't look like a real
     * opaque-tag payload — at minimum an MD5 / SHA / hex string. The
     * server is still the source of truth (the GET wins on collision),
     * so this is strictly safer than trusting the caller's string.
     */
    public function isTrustedEtag(string $etag): bool
    {
        if ($etag === '') {
            return false;
        }
        // Real CalDAV servers emit hex/alpha opaque tags inside the quotes.
        // 8 chars is the floor (matches Apache mod_dav's MD5 default).
        return preg_match('/^(W\/)?"[A-Za-z0-9._\-+]{8,}"$/', $etag) === 1;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function logHttpError(string $method, string $url, int $statusCode, string $responseBody, array $headers): void
    {
        // Per docs/08_logging.md: CalDAV response bodies may carry event content
        // (PII like summaries, descriptions, attendees). Log only a short, ASCII
        // preview at ERROR; keep the full body confined to DEBUG.
        $preview = mb_substr($responseBody, 0, 200);
        if (mb_strlen($responseBody) > 200) {
            $preview .= '…';
        }

        $this->logger?->error('CalDAV HTTP Error', [
            'method'           => $method,
            'url'              => $url,
            'status_code'      => $statusCode,
            'response_preview' => $preview,
            'www_authenticate' => $headers['www-authenticate'][0] ?? null,
        ]);

        $this->logger?->debug('CalDavCalendarTool: full HTTP error body', [
            'method'      => $method,
            'url'         => $url,
            'status_code' => $statusCode,
            'response'    => $responseBody,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function logHttpRequest(string $method, string $url, array $options): void
    {
        $this->logger?->debug('CalDavCalendarTool: HTTP request', [
            'method'     => $method,
            'url'        => $url,
            'headers'    => $options['headers'] ?? [],
            'auth_basic' => isset($options['auth_basic']) ? [$options['auth_basic'][0], '***'] : null,
            'timeout'    => $options['timeout'] ?? null,
        ]);
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function logHttpResponse(string $method, string $url, int $statusCode, array $headers): void
    {
        $this->logger?->debug('CalDavCalendarTool: HTTP response', [
            'method'           => $method,
            'url'              => $url,
            'status_code'      => $statusCode,
            'www_authenticate' => $headers['www-authenticate'][0] ?? null,
        ]);
    }
}
