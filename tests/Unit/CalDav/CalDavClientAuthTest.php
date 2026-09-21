<?php

declare(strict_types=1);

use Spora\Plugins\Calendar\CalDav\CalDavClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

const CAL_TEST_REALM    = 'NMMDav';
const CAL_TEST_NONCE    = '6ab0e144effbb';
const CAL_TEST_OPAQUE   = '67ae19481e1b8e71414bf772961967f0';
const CAL_TEST_USERNAME = 'cal002c48e';
const CAL_TEST_PASSWORD = 'BfNdeaBIOdQB18cm';

function calMakeDigestChallengeHeader(): array
{
    return ['www-authenticate' => [
        'Digest realm="' . CAL_TEST_REALM . '",qop="auth",nonce="' . CAL_TEST_NONCE . '",opaque="' . CAL_TEST_OPAQUE . '"',
    ]];
}

it('passes Basic preemptively in auto mode and does not retry on a 200', function () {
    $http = Mockery::mock(HttpClientInterface::class);
    $ok   = Mockery::mock(ResponseInterface::class);
    $ok->allows('getStatusCode')->andReturn(200);
    $ok->allows('getHeaders')->andReturn([]);

    // The wire call must include auth_basic; auth_method is consumed
    // by CalDavClient and must NOT leak to Symfony's HttpClient.
    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        return ($options['auth_basic'] ?? null) === [CAL_TEST_USERNAME, CAL_TEST_PASSWORD]
            && !array_key_exists('auth_method', $options);
    }))->once()->andReturn($ok);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => CalDavClient::AUTH_AUTO,
        'auth_basic'  => [CAL_TEST_USERNAME, CAL_TEST_PASSWORD],
    ]);
});

it('falls back to Digest in auto mode when the server challenges with Digest on 401', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $challenge401 = Mockery::mock(ResponseInterface::class);
    $challenge401->allows('getStatusCode')->andReturn(401);
    $challenge401->allows('getHeaders')->andReturn(calMakeDigestChallengeHeader());

    $ok = Mockery::mock(ResponseInterface::class);
    $ok->allows('getStatusCode')->andReturn(207);
    $ok->allows('getHeaders')->andReturn([]);

    // First request carries Basic (preemptive). Second request
    // (the digest retry) carries an Authorization: Digest header
    // and no auth_basic — Symfony would otherwise add Basic on top
    // of the explicit header and the server would reject the request.
    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        return ($options['auth_basic'] ?? null) === [CAL_TEST_USERNAME, CAL_TEST_PASSWORD];
    }))->ordered()->andReturn($challenge401);

    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        $auth = $options['headers']['Authorization'] ?? '';
        return str_starts_with($auth, 'Digest username="' . CAL_TEST_USERNAME . '"')
            && str_contains($auth, 'realm="' . CAL_TEST_REALM . '"')
            && str_contains($auth, 'nonce="' . CAL_TEST_NONCE . '"')
            && str_contains($auth, 'opaque="' . CAL_TEST_OPAQUE . '"')
            && !array_key_exists('auth_basic', $options);
    }))->ordered()->andReturn($ok);

    $client = new CalDavClient($http);
    $response = $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => CalDavClient::AUTH_AUTO,
        'auth_basic'  => [CAL_TEST_USERNAME, CAL_TEST_PASSWORD],
    ]);

    expect($response)->toBe($ok);
});

it('strips auth_basic from the first request when auth_method is digest', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $challenge401 = Mockery::mock(ResponseInterface::class);
    $challenge401->allows('getStatusCode')->andReturn(401);
    $challenge401->allows('getHeaders')->andReturn(calMakeDigestChallengeHeader());

    $ok = Mockery::mock(ResponseInterface::class);
    $ok->allows('getStatusCode')->andReturn(207);
    $ok->allows('getHeaders')->andReturn([]);

    // First request: NO Authorization header (server must be allowed
    // to issue its challenge without our preemptive Basic confusing it).
    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        return !isset($options['auth_basic'])
            && empty($options['headers']['Authorization'] ?? '');
    }))->ordered()->andReturn($challenge401);

    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        return str_starts_with($options['headers']['Authorization'] ?? '', 'Digest ');
    }))->ordered()->andReturn($ok);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => CalDavClient::AUTH_DIGEST,
        'auth_basic'  => [CAL_TEST_USERNAME, CAL_TEST_PASSWORD],
    ]);
});

it('does not retry when auth_method is basic even on a Digest challenge', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $challenge401 = Mockery::mock(ResponseInterface::class);
    $challenge401->allows('getStatusCode')->andReturn(401);
    $challenge401->allows('getHeaders')->andReturn(calMakeDigestChallengeHeader());

    // One and only one call — the operator pinned Basic, so the 401
    // is a credential error and must surface as-is to the caller.
    $http->expects('request')->once()->andReturn($challenge401);

    $client = new CalDavClient($http);
    $response = $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => CalDavClient::AUTH_BASIC,
        'auth_basic'  => [CAL_TEST_USERNAME, CAL_TEST_PASSWORD],
    ]);

    expect($response)->toBe($challenge401);
});

it('does not retry on a 401 that is not a Digest challenge', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $challenge401 = Mockery::mock(ResponseInterface::class);
    $challenge401->allows('getStatusCode')->andReturn(401);
    // Server says only Basic — credentials are wrong; we must not
    // mutate the response or attempt a retry that would loop.
    $challenge401->allows('getHeaders')->andReturn(['www-authenticate' => ['Basic realm="login"']]);

    $http->expects('request')->once()->andReturn($challenge401);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => CalDavClient::AUTH_AUTO,
        'auth_basic'  => [CAL_TEST_USERNAME, CAL_TEST_PASSWORD],
    ]);
});

it('does not retry on 401 when credentials are missing', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $challenge401 = Mockery::mock(ResponseInterface::class);
    $challenge401->allows('getStatusCode')->andReturn(401);
    $challenge401->allows('getHeaders')->andReturn(calMakeDigestChallengeHeader());

    // No auth_basic in the options → no retry path even on Digest
    // challenge. (The first request goes out anonymous and would
    // always 401, but the contract is "don't loop".)
    $http->expects('request')->once()->andReturn($challenge401);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => CalDavClient::AUTH_AUTO,
    ]);
});

it('defaults auth_method to auto when the option is missing', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $ok = Mockery::mock(ResponseInterface::class);
    $ok->allows('getStatusCode')->andReturn(200);
    $ok->allows('getHeaders')->andReturn([]);

    // No auth_method in options → auto → Basic preemptive.
    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        return ($options['auth_basic'] ?? null) === ['u', 'p']
            && !array_key_exists('auth_method', $options);
    }))->andReturn($ok);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/', ['auth_basic' => ['u', 'p']]);
});

it('passes a non-401 4xx through without retry', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $forbidden = Mockery::mock(ResponseInterface::class);
    $forbidden->allows('getStatusCode')->andReturn(403);
    $forbidden->allows('getHeaders')->andReturn([]);

    $http->expects('request')->once()->andReturn($forbidden);

    $client = new CalDavClient($http);
    $response = $client->request('DELETE', 'https://cal.example.com/event.ics', [
        'auth_method' => CalDavClient::AUTH_AUTO,
        'auth_basic'  => ['u', 'p'],
    ]);

    expect($response)->toBe($forbidden);
});

it('uses the URI path (without scheme/host) as the Digest uri parameter', function () {
    // The Digest `uri` must be the request-target, not the full URL
    // — RFC 7235 §3.4.6 says servers SHOULD 400 if they disagree.
    $http = Mockery::mock(HttpClientInterface::class);

    $challenge401 = Mockery::mock(ResponseInterface::class);
    $challenge401->allows('getStatusCode')->andReturn(401);
    $challenge401->allows('getHeaders')->andReturn(calMakeDigestChallengeHeader());

    $ok = Mockery::mock(ResponseInterface::class);
    $ok->allows('getStatusCode')->andReturn(200);
    $ok->allows('getHeaders')->andReturn([]);

    $http->expects('request')->with('GET', 'https://cal.example.com/cal/1.ics?foo=bar', Mockery::any())
        ->ordered()->andReturn($challenge401);

    $http->expects('request')->with('GET', 'https://cal.example.com/cal/1.ics?foo=bar', Mockery::on(function ($options) {
        return str_contains($options['headers']['Authorization'] ?? '', 'uri="/cal/1.ics?foo=bar"');
    }))->ordered()->andReturn($ok);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/cal/1.ics?foo=bar', [
        'auth_method' => CalDavClient::AUTH_AUTO,
        'auth_basic'  => [CAL_TEST_USERNAME, CAL_TEST_PASSWORD],
    ]);
});

it('falls back to an unknown auth_method value to auto behavior', function () {
    $http = Mockery::mock(HttpClientInterface::class);

    $ok = Mockery::mock(ResponseInterface::class);
    $ok->allows('getStatusCode')->andReturn(200);
    $ok->allows('getHeaders')->andReturn([]);

    // Garbage in the auth_method key must not throw — silently fall
    // back to auto so a stale or migrated setting cannot brick the
    // tool.
    $http->expects('request')->with('GET', 'https://cal.example.com/', Mockery::on(function ($options) {
        return ($options['auth_basic'] ?? null) === ['u', 'p']
            && !array_key_exists('auth_method', $options);
    }))->andReturn($ok);

    $client = new CalDavClient($http);
    $client->request('GET', 'https://cal.example.com/', [
        'auth_method' => 'bogus-scheme',
        'auth_basic'  => ['u', 'p'],
    ]);
});
