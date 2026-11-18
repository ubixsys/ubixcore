<?php

declare(strict_types=1);

namespace Ubix\Service\Vault;

use GuzzleHttp\ClientInterface as Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface as Logger;
use ReflectionClass;
use RuntimeException;
use TypeError;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Minimal client for uBix Vault — the uBix family's HashiCorp-Vault-compatible
 * secrets manager (see ~/git/ubixvault). Speaks the standard Vault HTTP API:
 * token and service-account JWT auth (`kubernetes` or `jwt` mount), KV v2 secret reads, and dynamic database credentials.
 *
 * This is deliberately read-only and stateless: callers pass the Vault address
 * and (post-auth) token into each method, so a single instance can serve both
 * the login call and the subsequent secret reads without holding mutable auth
 * state. Secret values are never logged.
 *
 * @see \Ubix\Tests\Service\Vault\VaultServiceTest PHPUnit test case
 */
final class VaultService
{
    /**
     * Auth mount used by {@see loginKubernetes()} and when `VAULT_AUTH_PATH` is unset
     */
    public const DEFAULT_AUTH_PATH = 'kubernetes';

    private const AUTH_LOGIN_TEMPLATE = '/v1/auth/%s/login';

    /**
     * One path segment: letters, digits, `-` and `_`. The mount arrives from an
     * environment variable, so without this a value like `../../sys` or `jwt/x` would
     * be interpolated straight into the request path.
     */
    private const AUTH_PATH_PATTERN = '/^[A-Za-z0-9_-]+\z/';

    private const AUTH_TOKEN_REVOKE_SELF = '/v1/auth/token/revoke-self';

    private const KV_V2_READ_PREFIX = '/v1/secret/data/';

    private const DATABASE_CREDS_PREFIX = '/v1/database/creds/';

    private const VAULT_TOKEN_HEADER = 'X-Vault-Token';

    /**
     * Constructor
     *
     * @param Logger      $logger      Logger
     * @param Client      $httpClient  Guzzle HTTP client
     * @param JsonService $jsonService JSON encode/decode service
     */
    public function __construct(
        private Logger $logger,
        private Client $httpClient,
        private JsonService $jsonService,
    ) {
    }

    /**
     * Authenticate via the Kubernetes auth method (the `kubernetes` mount) and return a client token.
     *
     * Shorthand for {@see loginWithServiceAccountJwt()} on the default mount; a failed
     * login, or one that returns no client token, surfaces from it as a RuntimeException.
     *
     * @param string $vaultAddress      Base address of the Vault server (e.g. https://vault.internal:8200)
     * @param string $role              The Kubernetes auth role to log in as
     * @param string $serviceAccountJwt The pod's service-account JWT
     *
     * @return string The issued Vault client token
     */
    public function loginKubernetes(string $vaultAddress, string $role, string $serviceAccountJwt): string
    {
        return $this->loginWithServiceAccountJwt($vaultAddress, self::DEFAULT_AUTH_PATH, $role, $serviceAccountJwt);
    }

    /**
     * Authenticate with the pod's service-account JWT on the given auth mount and return a client token.
     *
     * The pod presents its service-account JWT; Vault validates it against the bound
     * role and issues a short-lived client token. The mount selects the engine: the
     * `kubernetes` method (TokenReview against one API server) and the `jwt` method
     * (signature check against configured public keys, e.g. several clusters behind one
     * vault) take the same `{"role": ..., "jwt": ...}` body and differ only in the mount,
     * which may also have been enabled under another name.
     *
     * @param string $vaultAddress      Base address of the Vault server (e.g. https://vault.internal:8200)
     * @param string $authPath          Auth mount, one path segment (e.g. `kubernetes` or `jwt`)
     * @param string $role              The auth role to log in as
     * @param string $serviceAccountJwt The pod's service-account JWT
     *
     * @throws RuntimeException If the mount is not a single safe path segment, the login fails,
     *                          or the response carries no client token
     *
     * @return string The issued Vault client token
     */
    public function loginWithServiceAccountJwt(string $vaultAddress, string $authPath, string $role, string $serviceAccountJwt): string
    {
        if (preg_match(self::AUTH_PATH_PATTERN, $authPath) !== 1) {
            throw new RuntimeException(
                'VAULT_AUTH_PATH must be a single path segment of letters, digits, `-` or `_`; got `' . $authPath . '`',
            );
        }

        $response = $this->send($vaultAddress, 'POST', sprintf(self::AUTH_LOGIN_TEMPLATE, $authPath), null, [
            'jwt'  => $serviceAccountJwt,
            'role' => $role,
        ]);

        $auth  = $response['auth'] ?? null;
        $token = is_array($auth) ? ($auth['client_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('uBix Vault login at auth/' . $authPath . ' did not return a client_token');
        }

        return $token;
    }

    /**
     * Read a KV v2 secret and return its inner data map.
     *
     * @param string $vaultAddress Base address of the Vault server
     * @param string $token        A valid Vault client token
     * @param string $path         KV v2 path relative to the secret mount (e.g. `app/db`)
     *
     * @throws RuntimeException If the read fails or the response has no data map
     *
     * @return array<string, string> The secret's key/value pairs
     */
    public function readKvV2Secret(string $vaultAddress, string $token, string $path): array
    {
        $response = $this->send($vaultAddress, 'GET', self::KV_V2_READ_PREFIX . ltrim($path, '/'), $token);

        $outer = $response['data'] ?? null;
        $inner = is_array($outer) ? ($outer['data'] ?? null) : null;

        if (! is_array($inner)) {
            throw new RuntimeException('uBix Vault KV read for `' . $path . '` returned no data map');
        }

        $secret = [];
        foreach ($inner as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $secret[$key] = (string) $value;
            }
        }

        return $secret;
    }

    /**
     * Revoke the given token (the token revokes itself).
     *
     * For tokens this process obtained by logging in and no longer needs: a token
     * left to expire stays a valid credential until it does, and uBix Vault keeps a
     * record of it until then.
     *
     * @param string $vaultAddress Base address of the Vault server
     * @param string $token        The token to revoke (a failed request surfaces as a RuntimeException)
     *
     * @return void
     */
    public function revokeSelf(string $vaultAddress, string $token): void
    {
        $this->send($vaultAddress, 'POST', self::AUTH_TOKEN_REVOKE_SELF, $token);
    }

    /**
     * Read dynamic database credentials for a role.
     *
     * @param string $vaultAddress Base address of the Vault server
     * @param string $token        A valid Vault client token
     * @param string $role         The database role whose credentials to generate
     *
     * @throws RuntimeException If the read fails or returns no username/password
     *
     * @return array{username: string, password: string} The generated credentials
     */
    public function readDatabaseCredentials(string $vaultAddress, string $token, string $role): array
    {
        $response = $this->send($vaultAddress, 'GET', self::DATABASE_CREDS_PREFIX . rawurlencode($role), $token);

        $data     = $response['data'] ?? null;
        $username = is_array($data) ? ($data['username'] ?? null) : null;
        $password = is_array($data) ? ($data['password'] ?? null) : null;

        if (! is_string($username) || ! is_string($password) || $username === '') {
            throw new RuntimeException('uBix Vault database creds for role `' . $role . '` returned no username/password');
        }

        return ['username' => $username, 'password' => $password];
    }

    /**
     * Perform a Vault HTTP request and return the decoded JSON body.
     *
     * @param string                     $vaultAddress Base address of the Vault server
     * @param string                     $method       HTTP method
     * @param string                     $path         Request path (leading slash)
     * @param ?string                    $token        Vault token to send, or null for unauthenticated calls (login)
     * @param array<string, string>|null $jsonBody     Optional JSON request body
     *
     * @throws RuntimeException If the request fails at the transport level, or a 2xx body is not a JSON object or array
     *
     * @return array<mixed> The decoded JSON response body
     */
    private function send(string $vaultAddress, string $method, string $path, ?string $token, ?array $jsonBody = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers[self::VAULT_TOKEN_HEADER] = $token;
        }

        $options = [
            'headers'     => $headers,
            'http_errors' => true,
            'timeout'     => 5,
        ];
        if ($jsonBody !== null) {
            $headers['Content-Type'] = 'application/json';
            $options['headers']      = $headers;
            $options['body']         = $this->jsonService->encode($jsonBody);
        }

        try {
            $response = $this->httpClient->request($method, rtrim($vaultAddress, '/') . $path, $options);
        } catch (GuzzleException $exception) {
            $this->logger->error('uBix Vault request failed', ['method' => $method, 'path' => $path]);
            throw new RuntimeException('uBix Vault request to `' . $path . '` failed: ' . $exception->getMessage(), 0, $exception);
        }

        $body = (string) $response->getBody();

        // Some endpoints (e.g. revoke-self) answer 204 with no body.
        if (trim($body) === '') {
            return [];
        }

        //
        //  The decode is guarded the same way the transport call is. JsonService::decode()
        //  throws DtoException (an Exception, not a RuntimeException) for a body that is
        //  not JSON, and its array return type turns a scalar JSON body (`"ok"`, `1`) into
        //  a TypeError. Either would escape the RuntimeException contract every caller of
        //  this class relies on, e.g. an HTML error page served with a 200 by a proxy.
        //
        //  The decode error is NOT chained as the previous exception: DtoException's
        //  JsonError keeps the whole input, and a truncated KV response can hold secret
        //  values, which anything that logs or renders the exception chain would print.
        //  Only its class name is kept.
        //
        try {
            return $this->jsonService->decode($body);
        } catch (DtoException | TypeError $exception) { // @phpstan-ignore catch.neverThrown (JsonService::decode() raises TypeError from its array return type for a scalar JSON body; PHPStan reads only its @throws)
            $cause = (new ReflectionClass($exception))->getShortName();

            $this->logger->error('uBix Vault response was not decodable JSON', ['cause' => $cause, 'method' => $method, 'path' => $path, 'status' => $response->getStatusCode()]);
            throw new RuntimeException('uBix Vault request to `' . $path . '` returned a ' . $response->getStatusCode() . ' whose body is not a JSON object or array (' . $cause . ')');
        }
    }
}
