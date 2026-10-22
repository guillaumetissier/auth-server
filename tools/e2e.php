<?php

declare(strict_types=1);

/*
 * End-to-end check of a running auth server, over real HTTP, as a browser and a client would use it.
 *
 *   ISSUER=http://localhost:8080 CONSOLE="docker compose exec -T auth-server php bin/console" php tools/e2e.php
 *
 * ISSUER   base URL of the server (default http://localhost:8080)
 * CONSOLE  how to run bin/console against that server's database (default "php bin/console")
 *
 * Creates its own client and users (unique names, so it can run again on the same database), then plays
 * the flows and the attacks; prints PASS/FAIL per scenario, exit code 1 on failure. Needs the server to
 * run with APP_2FA_MODE=grace or optional (the default): users without 2FA enroll during the flow.
 */

use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use GuillaumeTissier\ResourceGuard\Dpop\DpopProofValidator;
use GuillaumeTissier\ResourceGuard\Dpop\InMemoryReplayStore;
use GuillaumeTissier\ResourceGuard\Guard\AuthenticationFailed;
use GuillaumeTissier\ResourceGuard\Guard\Principal;
use GuillaumeTissier\ResourceGuard\Guard\ResourceGuard;
use GuillaumeTissier\ResourceGuard\Token\AccessTokenValidator;
use GuillaumeTissier\ResourceGuard\Token\StaticJwksProvider;
use OTPHP\TOTP;

require __DIR__.'/../vendor/autoload.php';

const REDIRECT_URI = 'http://localhost:5173/callback';
const API = 'https://api.example.test/items';

/**
 * One browser: cookies kept, redirects not followed (each step is checked).
 */
final class Browser
{
    private CurlHandle $curl;

    public function __construct(private readonly string $issuer)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            \CURLOPT_COOKIEFILE => '', // in-memory cookie jar
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT => 20,
        ]);
    }

    /**
     * @param array<string, string> $form    url-encoded body (POST) when not empty
     * @param list<string>          $headers
     *
     * @return array{int, string, string} status, Location header (absolute, '' when none), body
     */
    public function request(string $method, string $url, array $form = [], array $headers = [], ?string $body = null): array
    {
        $url = str_starts_with($url, 'http') ? $url : $this->issuer.$url;
        if ('' === $url) {
            throw new InvalidArgumentException('Empty URL.');
        }
        $location = '';
        curl_setopt_array($this->curl, [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method ?: 'GET',
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_POSTFIELDS => [] !== $form ? http_build_query($form) : ($body ?? ''),
            \CURLOPT_POST => 'GET' !== $method,
            \CURLOPT_HTTPGET => 'GET' === $method,
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location): int {
                if (0 === stripos($line, 'Location:')) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
        ]);
        $content = curl_exec($this->curl);
        if (!is_string($content)) {
            throw new RuntimeException(sprintf('%s %s: %s', $method, $url, curl_error($this->curl)));
        }
        if ('' !== $location && !str_starts_with($location, 'http')) {
            $location = $this->issuer.$location;
        }

        return [(int) curl_getinfo($this->curl, \CURLINFO_RESPONSE_CODE), $location, $content];
    }
}

final class E2e
{
    /** @var list<array{bool, string, string}> */
    private array $results = [];
    /** @var array<string, string> last TOTP code used per secret: a code is never accepted twice */
    private array $usedCodes = [];
    private string $run;

    public function __construct(private readonly string $issuer, private readonly string $console)
    {
        $this->run = bin2hex(random_bytes(3));
    }

    public function run(): int
    {
        $clientId = 'e2e-'.$this->run;
        $alice = ['e2e-alice-'.$this->run, 'e2e-'.$this->run.'-1001'];
        $carla = ['e2e-carla-'.$this->run, 'e2e-'.$this->run.'-9001'];
        $password = 'E2e-password-'.$this->run;

        // --- Setup through the console, as an operator would.
        $this->console(['league:oauth2-server:create-client', 'E2E', $clientId, '--public', '--redirect-uri='.REDIRECT_URI,
            '--grant-type=authorization_code', '--grant-type=refresh_token', '--scope=profile', '--scope=offline_access']);
        $this->console(['app:user:create', $alice[0], $alice[1], '--password='.$password]);
        $this->console(['app:user:create', $carla[0], $carla[1], '--password='.$password]);
        $this->console(['app:user:role', $carla[0], 'ROLE_ADMIN']);
        $domain = 'e2e-'.$this->run;
        $this->console(['app:resource-type', 'vehicle', 'Vehicles']);
        $this->console(['app:domain', $domain, '--create=E2E '.$this->run]);
        $this->console(['app:access', $alice[0], '--move='.$domain]);
        $this->console(['app:access', $alice[0], $domain, '*:r vehicle:w', '--subdomains']);

        // --- Server up and documented
        $b = new Browser($this->issuer);
        [$status, , $body] = $b->request('GET', '/health');
        $this->check('Health endpoint', 200 === $status, (string) $status);
        [$status, , $body] = $b->request('GET', '/.well-known/oauth-authorization-server');
        $metadata = self::json($body);
        $this->check('Metadata: PKCE S256 only, DPoP algorithms', 200 === $status && ['S256'] === ($metadata['code_challenge_methods_supported'] ?? null)
            && is_array($metadata['dpop_signing_alg_values_supported'] ?? null), (string) $status);
        [, , $body] = $b->request('GET', '/.well-known/jwks.json');
        $keys = self::json($body)['keys'] ?? null;
        $key = is_array($keys) && is_array($keys[0] ?? null) ? $keys[0] : [];
        $this->check('JWKS: RSA public key, no private part', 'RSA' === ($key['kty'] ?? null) && !isset($key['d']), json_encode($key) ?: '');
        $guard = new ResourceGuard(new AccessTokenValidator(StaticJwksProvider::fromJwksJson($body), [$clientId]), new DpopProofValidator(new InMemoryReplayStore()));

        // --- Login, 2FA enrollment, code, tokens
        $bad = new Browser($this->issuer);
        $noSecret = null;
        [$status, $location] = $this->authorize($bad, $clientId, $alice[0], 'wrong-password', $noSecret);
        $this->check('Wrong password: no code', '' === $location || !str_contains($location, 'code='), $location);

        $aliceSecret = null;
        $flow = $this->authorize($b, $clientId, $alice[0], $password, $aliceSecret);
        $this->check('Login + 2FA enrollment + consent-free authorization: code issued', null !== $flow[2] && null !== $aliceSecret, $flow[1]);
        $key = DpopProofFactory::withNewKey();
        $tokens = $this->tokenRequest($b, $clientId, ['grant_type' => 'authorization_code', 'code' => (string) $flow[2], 'redirect_uri' => REDIRECT_URI, 'code_verifier' => $flow[3]], $key);
        $this->check('Code exchanged with a DPoP proof: DPoP-bound tokens', 200 === $tokens[0] && 'DPoP' === ($tokens[1]['token_type'] ?? null), $tokens[2]);
        $access = self::str($tokens[1], 'access_token');
        $refresh = self::str($tokens[1], 'refresh_token');
        $claims = self::claims($access);
        $this->check('Access token: sub = external id, bound to the client key', $alice[1] === ($claims['sub'] ?? null)
            && is_array($claims['cnf'] ?? null) && $key->thumbprint() === ($claims['cnf']['jkt'] ?? null), json_encode($claims) ?: '');

        $acl = is_array($claims['acl'] ?? null) ? $claims['acl'] : [];
        $this->check('Access token carries the domain and the rights', '/'.$domain.'/' === ($claims['dom'] ?? null)
            && in_array(['/'.$domain.'/', true, '*:r vehicle:w'], $acl, true), json_encode([$claims['dom'] ?? null, $acl]) ?: '');
        $principal = $this->protectedCall($guard, 'GET', $access, $key);
        $this->check('Rights checked by the resource server: subdomain yes, other domain no', $principal instanceof Principal
            && $principal->can('vehicle', GuillaumeTissier\ResourceGuard\Access\AccessLevel::Write, '/'.$domain.'/branch/')
            && !$principal->can('vehicle', GuillaumeTissier\ResourceGuard\Access\AccessLevel::Read, '/elsewhere/'), '');

        // Signed in now (unauthenticated requests are sent to the login page before any check).
        [$status, $location] = $b->request('GET', '/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => REDIRECT_URI, 'scope' => 'profile', 'state' => 's']));
        $this->check('Authorization request without PKCE: refused', 400 === $status || str_contains($location, 'error='), $status.' '.$location);

        $replayed = $this->tokenRequest($b, $clientId, ['grant_type' => 'authorization_code', 'code' => (string) $flow[2], 'redirect_uri' => REDIRECT_URI, 'code_verifier' => $flow[3]], $key);
        $this->check('Authorization code used twice: refused', 400 === $replayed[0], $replayed[2]);

        // --- Resource server
        $principal = $this->protectedCall($guard, 'GET', $access, $key);
        $this->check('Resource server: token + proof accepted', $principal instanceof Principal && $alice[1] === $principal->userId, is_string($principal) ? $principal : '');
        $stolen = $this->protectedCall($guard, 'GET', $access, DpopProofFactory::withNewKey());
        $this->check('Resource server: stolen token (other key) refused', 'invalid_dpop_proof' === $stolen, is_string($stolen) ? $stolen : 'accepted');

        // --- Refresh
        $thief = $this->tokenRequest($b, $clientId, ['grant_type' => 'refresh_token', 'refresh_token' => $refresh], DpopProofFactory::withNewKey());
        $this->check('Refresh with another key (stolen refresh token): refused', 400 === $thief[0], $thief[2]);
        $refreshed = $this->tokenRequest($b, $clientId, ['grant_type' => 'refresh_token', 'refresh_token' => $refresh], $key);
        $this->check('Refresh with the bound key: new tokens', 200 === $refreshed[0], $refreshed[2]);
        $old = $this->tokenRequest($b, $clientId, ['grant_type' => 'refresh_token', 'refresh_token' => $refresh], $key);
        $this->check('Rotated refresh token: refused', 400 === $old[0], $old[2]);

        // --- Second login: the TOTP code is now required
        $again = new Browser($this->issuer);
        $flow = $this->authorize($again, $clientId, $alice[0], $password, $aliceSecret);
        $this->check('New session: password + TOTP code, code issued', null !== $flow[2], $flow[1]);

        // --- CORS for browser clients
        [$status] = (new Browser($this->issuer))->request('OPTIONS', '/token', [], ['Origin: http://localhost:5173', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: dpop, content-type']);
        $this->check('CORS preflight on /token answered', 204 === $status, (string) $status);

        // --- Impersonation by an administrator (read-only)
        $admin = new Browser($this->issuer);
        $carlaSecret = null;
        $flow = $this->authorize($admin, $clientId, $carla[0], $password, $carlaSecret, ['impersonate' => $alice[1], 'scope' => 'profile offline_access']);
        $this->check('Administrator: impersonation asks for a reason and a fresh code', null !== $flow[4], $flow[1]);
        if (null !== $flow[4] && null !== $carlaSecret) {
            [$status, $location, $body] = $admin->request('GET', $flow[4]);
            [$status, $location] = $admin->request('POST', $flow[4], [
                'reason' => 'E2E check '.$this->run.', support ticket',
                'code' => $this->totp($carlaSecret),
                '_csrf_token' => self::csrf($body),
            ]);
            $flow = $this->follow($admin, $location, $carlaSecret, $flow[3]);
            $adminKey = DpopProofFactory::withNewKey();
            $imp = $this->tokenRequest($admin, $clientId, ['grant_type' => 'authorization_code', 'code' => (string) $flow[2], 'redirect_uri' => REDIRECT_URI, 'code_verifier' => $flow[3]], $adminKey);
            $impClaims = self::claims(self::str($imp[1], 'access_token'));
            $this->check('Impersonation token: sub = user, act = administrator, read-only', $alice[1] === ($impClaims['sub'] ?? null)
                && ['sub' => $carla[1]] === ($impClaims['act'] ?? null) && 'read_only' === ($impClaims['imp_mode'] ?? null), json_encode($impClaims) ?: '');
            $write = $this->protectedCall($guard, 'POST', self::str($imp[1], 'access_token'), $adminKey);
            $this->check('Read-only impersonation: writes refused by the resource server', 'impersonation_read_only' === $write, is_string($write) ? $write : 'accepted');

            $endUrl = $this->issuer.'/impersonation/end';
            $impAccess = self::str($imp[1], 'access_token');
            [$status] = $admin->request('POST', $endUrl, [], ['Authorization: DPoP '.$impAccess, 'DPoP: '.$adminKey->create('POST', $endUrl, $impAccess)]);
            $this->check('Ending the impersonation', 204 === $status, (string) $status);
            $after = $this->tokenRequest($admin, $clientId, ['grant_type' => 'refresh_token', 'refresh_token' => self::str($imp[1], 'refresh_token')], $adminKey);
            $this->check('After the end, its refresh token is refused', 400 === $after[0], $after[2]);
        }

        // --- Regular users cannot impersonate
        $flow = $this->authorize(new Browser($this->issuer), $clientId, $alice[0], $password, $aliceSecret, ['impersonate' => $carla[1]]);
        $this->check('Regular user asking to impersonate: access_denied', str_contains($flow[1], 'error=access_denied'), $flow[1]);

        foreach ($this->results as [$ok, $name, $detail]) {
            printf("  %s  %-70s %s\n", $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m", $name, $ok ? '' : '→ '.substr($detail, 0, 160));
        }
        $failed = count(array_filter($this->results, static fn (array $r): bool => !$r[0]));
        printf("\n%d/%d scenarios passed\n", count($this->results) - $failed, count($this->results));

        return 0 === $failed ? 0 : 1;
    }

    /**
     * Runs the browser part of an authorization request.
     *
     * @param array<string, string> $extra
     *
     * @return array{int, string, ?string, string, ?string} last status, last Location, code, PKCE verifier, impersonation confirmation URL
     */
    private function authorize(Browser $b, string $clientId, string $username, string $password, ?string &$secret, array $extra = []): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $query = $extra + [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => REDIRECT_URI, 'scope' => 'profile offline_access',
            'state' => 'st-'.$this->run, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        [$status, $location] = $b->request('GET', '/authorize?'.http_build_query($query));
        if (!str_ends_with(parse_url($location, \PHP_URL_PATH) ?: '', '/login')) {
            return $this->follow($b, $location, $secret, $verifier) + [0 => $status];
        }
        [, , $body] = $b->request('GET', '/login');
        [$status, $location] = $b->request('POST', '/login', ['_username' => $username, '_password' => $password, '_csrf_token' => self::csrf($body)]);
        if (str_ends_with(parse_url($location, \PHP_URL_PATH) ?: '', '/login')) {
            return [$status, $location, null, $verifier, null];
        }

        return $this->follow($b, $location, $secret, $verifier);
    }

    /**
     * Follows redirects, completing the 2FA steps on the way, until the client's redirect URI.
     *
     * @return array{int, string, ?string, string, ?string}
     */
    private function follow(Browser $b, string $location, ?string &$secret, string $verifier): array
    {
        $status = 302;
        for ($i = 0; $i < 12 && '' !== $location; ++$i) {
            if (str_starts_with($location, REDIRECT_URI)) {
                parse_str((string) parse_url($location, \PHP_URL_QUERY), $params);
                $code = $params['code'] ?? null;

                return [$status, $location, is_string($code) && ('st-'.$this->run) === ($params['state'] ?? null) ? $code : null, $verifier, null];
            }
            $path = parse_url($location, \PHP_URL_PATH) ?: '';
            [$status, $next, $body] = $b->request('GET', $location);

            if ('/impersonation/confirm' === $path) {
                return [$status, $location, null, $verifier, $location];
            }
            if ('/2fa' === $path && null !== $secret) {
                [$status, $next] = $b->request('POST', '/2fa_check', ['_auth_code' => $this->totp($secret), '_csrf_token' => self::csrf($body)]);
            } elseif ('/account/2fa/setup' === $path && 1 === preg_match('#<strong>([A-Z2-7 ]+)</strong>#', $body, $m)) {
                $secret = str_replace(' ', '', $m[1]); // displayed in groups of four
                [$status, $next] = $b->request('POST', $location, ['code' => $this->totp($secret), '_csrf_token' => self::csrf($body)]);
            } elseif ('/account/2fa/backup-codes' === $path && 1 === preg_match('#href="([^"]+)">I have saved them#', $body, $m)) {
                $next = html_entity_decode($m[1]);
                $next = str_starts_with($next, 'http') ? $next : $this->issuer.$next;
            }
            $location = $next;
        }

        return [$status, $location, null, $verifier, null];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{int, array<mixed>, string} status, decoded body, raw body
     */
    private function tokenRequest(Browser $b, string $clientId, array $params, DpopProofFactory $key): array
    {
        $url = $this->issuer.'/token';
        [$status, , $body] = $b->request('POST', $url, $params + ['client_id' => $clientId], ['DPoP: '.$key->create('POST', $url)]);

        return [$status, self::json($body), $body];
    }

    private function protectedCall(ResourceGuard $guard, string $method, string $token, DpopProofFactory $key): Principal|string
    {
        try {
            return $guard->authenticate($method, API, 'DPoP '.$token, [$key->create($method, API, $token)]);
        } catch (AuthenticationFailed $e) {
            return $e->errorCode;
        }
    }

    /**
     * A TOTP code never used before for this secret (waits for the next 30 s window when needed).
     */
    private function totp(string $secret): string
    {
        if ('' === $secret) {
            throw new RuntimeException('empty TOTP secret');
        }
        $totp = TOTP::createFromSecret($secret);
        for ($code = $totp->now(); ($this->usedCodes[$secret] ?? null) === $code; $code = $totp->now()) {
            sleep(1);
        }

        return $this->usedCodes[$secret] = $code;
    }

    /**
     * @param list<string> $arguments
     */
    private function console(array $arguments): void
    {
        $command = $this->console.' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1';
        exec($command, $output, $exitCode);
        if (0 !== $exitCode) {
            throw new RuntimeException("Setup command failed: {$command}\n".implode("\n", $output));
        }
    }

    private function check(string $name, bool $ok, string $detail): void
    {
        $this->results[] = [$ok, $name, $detail];
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $body): array
    {
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<mixed>
     */
    private static function claims(string $jwt): array
    {
        return self::json((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')));
    }

    /**
     * @param array<mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private static function csrf(string $html): string
    {
        return 1 === preg_match('#name="_csrf_token" value="([^"]+)"#', $html, $m) ? html_entity_decode($m[1]) : '';
    }
}

try {
    exit((new E2e(rtrim(getenv('ISSUER') ?: 'http://localhost:8080', '/'), getenv('CONSOLE') ?: 'php bin/console'))->run());
} catch (Throwable $e) {
    fwrite(\STDERR, 'Error: '.$e->getMessage()."\n");
    exit(1);
}
