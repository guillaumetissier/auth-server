<?php

declare(strict_types=1);

/**
 * Manual test helper for the auth server: plays the browser client (PKCE, DPoP proofs, token
 * requests) and a resource server (token + proof verification). Not for production use.
 *
 *   php tools/oauth-cli.php help
 *
 * State (PKCE verifier, DPoP key, last tokens) is kept in .oauth-cli/ next to this file's parent.
 */

require __DIR__.'/../vendor/autoload.php';

use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use GuillaumeTissier\ResourceGuard\Dpop\DpopProofValidator;
use GuillaumeTissier\ResourceGuard\Dpop\InMemoryReplayStore;
use GuillaumeTissier\ResourceGuard\Guard\AuthenticationFailed;
use GuillaumeTissier\ResourceGuard\Guard\ResourceGuard;
use GuillaumeTissier\ResourceGuard\Token\AccessTokenValidator;
use GuillaumeTissier\ResourceGuard\Token\StaticJwksProvider;
use Jose\Component\Core\JWK;
use OTPHP\TOTP;

final class OAuthCli
{
    private string $stateDir;
    private string $issuer;
    private string $clientId;
    private string $redirectUri;

    public function __construct()
    {
        $this->stateDir = dirname(__DIR__).'/.oauth-cli';
        if (!is_dir($this->stateDir)) {
            mkdir($this->stateDir, 0700, true);
        }
        $this->issuer = rtrim(getenv('ISSUER') ?: 'http://localhost:8080', '/');
        $this->clientId = getenv('CLIENT_ID') ?: 'my-spa';
        $this->redirectUri = getenv('REDIRECT_URI') ?: 'http://localhost:5173/callback';
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        return match ($command) {
            'authorize-url' => $this->authorizeUrl($args),
            'new-key' => $this->out($this->newKey($args[0] ?? 'default')),
            'thumbprint' => $this->out($this->dpop($args[0] ?? 'default')->thumbprint()),
            'proof' => $this->out($this->dpop($args[3] ?? 'default')->create(
                $args[0] ?? throw new InvalidArgumentException('usage: proof METHOD URL [ACCESS_TOKEN] [KEY]'),
                $args[1] ?? throw new InvalidArgumentException('usage: proof METHOD URL [ACCESS_TOKEN] [KEY]'),
                $args[2] ?? null,
            )),
            'token' => $this->token($args),
            'refresh' => $this->refresh($args),
            'decode' => $this->decode($args[0] ?? $this->lastToken('access_token')),
            'call' => $this->call($args),
            'end-impersonation' => $this->endImpersonation($args),
            'totp' => $this->out($this->totp($args[0] ?? '')),
            default => $this->help(),
        };
    }

    private function help(): int
    {
        echo <<<TXT
        Environment: ISSUER (http://localhost:8080), CLIENT_ID (my-spa), REDIRECT_URI (http://localhost:5173/callback)

          authorize-url [extra=value ...]   PKCE + state, prints the /authorize URL to open in a browser
                                            (extra params override, e.g. code_challenge_method=plain)
          new-key [name]                    new ES256 DPoP key (default key: "default")
          thumbprint [name]                 RFC 7638 thumbprint (jkt) of a key
          proof METHOD URL [token] [name]   prints a DPoP proof header value
          token CODE [--key=name] [--no-dpop] [--verifier=...]
                                            exchanges an authorization code (stores the tokens)
          refresh [--key=name] [--no-dpop] [--token=...]
                                            redeems the stored (or given) refresh token
          decode [JWT]                      prints header + claims of the stored (or given) access token
          call [URL] [--key=name] [--bearer] [--token=...] [--method=POST]
                                            simulates a resource server (your API) receiving a DPoP request:
                                            fetches the JWKS, verifies token + proof, prints the principal
          end-impersonation [--key=name]    ends the impersonation of the stored access token
          totp SECRET                       current TOTP code for a secret (as shown on the setup page)

        Impersonation: authorize-url impersonate=<external user id>, then token / call / refresh as usual.

        TXT;

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function authorizeUrl(array $args): int
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(8));
        $this->save('pkce.json', ['verifier' => $verifier, 'state' => $state]);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'profile offline_access',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        foreach ($args as $arg) {
            [$k, $v] = explode('=', $arg, 2) + [1 => ''];
            if ('' === $v) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }

        echo $this->issuer.'/authorize?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986)."\n";
        fwrite(\STDERR, "state={$state} (check it in the redirect), verifier saved\n");

        return 0;
    }

    private function newKey(string $name): string
    {
        $factory = DpopProofFactory::withNewKey();
        $this->save("key-{$name}.json", $factory->privateKey()->all());

        return $factory->thumbprint();
    }

    /**
     * @param list<string> $args
     */
    private function token(array $args): int
    {
        [$positional, $flags] = $this->options($args);
        $code = $positional[0] ?? throw new InvalidArgumentException('usage: token CODE');
        $verifier = self::flag($flags, 'verifier') ?? $this->load('pkce.json')['verifier'] ?? null;
        if (!is_string($verifier)) {
            throw new RuntimeException("No PKCE verifier stored: run 'authorize-url' first");
        }

        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier,
        ], $flags);
    }

    /**
     * @param list<string> $args
     */
    private function refresh(array $args): int
    {
        [, $flags] = $this->options($args);

        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => self::flag($flags, 'token') ?? $this->lastToken('refresh_token'),
        ], $flags);
    }

    /**
     * @param array<string, string>      $params
     * @param array<string, string|true> $flags
     */
    private function tokenRequest(array $params, array $flags): int
    {
        $url = $this->issuer.'/token';
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if (!isset($flags['no-dpop'])) {
            $headers[] = 'DPoP: '.$this->dpop(self::flag($flags, 'key') ?? 'default')->create('POST', $url);
        }

        [$status, $body] = $this->http('POST', $url, $headers, http_build_query($params + ['client_id' => $this->clientId]));
        echo "HTTP {$status}\n{$body}\n";

        $json = json_decode($body, true);
        if (200 === $status && is_array($json) && isset($json['access_token'])) {
            $this->save('tokens.json', $json);
        }

        return 200 === $status ? 0 : 1;
    }

    private function decode(string $jwt): int
    {
        [$h, $p] = explode('.', $jwt);
        $dec = static fn (string $s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
        echo json_encode(['header' => $dec($h), 'claims' => $dec($p)], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function call(array $args): int
    {
        [$positional, $flags] = $this->options($args);
        $url = $positional[0] ?? 'https://api.localhost/items';
        $token = self::flag($flags, 'token') ?? $this->lastToken('access_token');

        [$status, $jwks] = $this->http('GET', $this->issuer.'/.well-known/jwks.json');
        if (200 !== $status) {
            fwrite(\STDERR, "Cannot fetch JWKS (HTTP {$status})\n");

            return 1;
        }

        $guard = new ResourceGuard(
            new AccessTokenValidator(StaticJwksProvider::fromJwksJson($jwks), [$this->clientId]),
            new DpopProofValidator(new InMemoryReplayStore()),
            requireDpop: !isset($flags['bearer']),
        );

        $method = strtoupper(self::flag($flags, 'method') ?? 'GET');
        try {
            if (isset($flags['bearer'])) {
                $principal = $guard->authenticate($method, $url, 'Bearer '.$token);
            } else {
                $proof = $this->dpop(self::flag($flags, 'key') ?? 'default')->create($method, $url, $token);
                $principal = $guard->authenticate($method, $url, 'DPoP '.$token, [$proof]);
            }
        } catch (AuthenticationFailed $e) {
            echo "{$e->statusCode} {$e->errorCode}: {$e->getMessage()}\nWWW-Authenticate: {$e->wwwAuthenticate()}\n";

            return 1;
        }

        echo "200 OK user={$principal->userId} client={$principal->clientId} scopes=".implode(',', $principal->scopes)
            .' proof_of_possession='.($principal->proofOfPossession ? 'yes' : 'no')
            .($principal->isImpersonated() ? " actor={$principal->actorId()} mode={$principal->token->impersonationMode()}" : '')."\n";

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function endImpersonation(array $args): int
    {
        [, $flags] = $this->options($args);
        $url = $this->issuer.'/impersonation/end';
        $token = $this->lastToken('access_token');
        [$status, $body] = $this->http('POST', $url, [
            'Authorization: DPoP '.$token,
            'DPoP: '.$this->dpop(self::flag($flags, 'key') ?? 'default')->create('POST', $url, $token),
        ]);
        echo "HTTP {$status}\n{$body}\n";

        return 204 === $status ? 0 : 1;
    }

    private function dpop(string $name): DpopProofFactory
    {
        $file = "key-{$name}.json";
        if (!is_file($this->stateDir.'/'.$file)) {
            $this->newKey($name);
        }

        return new DpopProofFactory(new JWK($this->load($file)));
    }

    private function lastToken(string $field): string
    {
        $value = $this->load('tokens.json')[$field] ?? null;

        return is_string($value) ? $value : throw new RuntimeException("No {$field} stored: run 'token' first");
    }

    private function totp(string $secret): string
    {
        $secret = str_replace(' ', '', $secret);
        if ('' === $secret) {
            throw new InvalidArgumentException('usage: totp SECRET');
        }

        return TOTP::createFromSecret($secret)->now();
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private function http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_CUSTOMREQUEST => $method ?: 'GET',
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_TIMEOUT => 10,
        ]);
        if (null !== $body) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if (false === $response) {
            throw new RuntimeException('HTTP error: '.curl_error($ch));
        }

        return [(int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE), (string) $response];
    }

    /**
     * @param list<string> $args
     *
     * @return array{list<string>, array<string, string|true>} positional arguments, --flags
     */
    private function options(array $args): array
    {
        $positional = [];
        $flags = [];
        foreach ($args as $arg) {
            if (1 === preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
                $flags[$m[1]] = $m[2] ?? true;
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $flags];
    }

    /**
     * @param array<string, string|true> $flags
     */
    private static function flag(array $flags, string $name): ?string
    {
        $value = $flags[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function save(string $file, array $data): void
    {
        file_put_contents($this->stateDir.'/'.$file, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        chmod($this->stateDir.'/'.$file, 0600);
    }

    /**
     * @return array<mixed>
     */
    private function load(string $file): array
    {
        $path = $this->stateDir.'/'.$file;
        if (!is_file($path)) {
            throw new RuntimeException("Missing {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : throw new RuntimeException("{$path} is not valid JSON");
    }

    private function out(string $line): int
    {
        echo $line."\n";

        return 0;
    }
}

try {
    exit((new OAuthCli())->run($argv));
} catch (Throwable $e) {
    fwrite(\STDERR, 'Error: '.$e->getMessage()."\n");
    exit(2);
}
