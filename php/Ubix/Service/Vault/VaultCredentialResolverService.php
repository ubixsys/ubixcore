<?php

declare(strict_types=1);

namespace Ubix\Service\Vault;

use Psr\Log\LoggerInterface as Logger;
use Psr\SimpleCache\CacheInterface as SimpleCache;
use RuntimeException;
use Throwable;

/**
 * Resolves the application's database credentials from uBix Vault at process
 * startup and injects them into the environment, so the rest of the app
 * (notably {@see \Ubix\Service\Sql\MysqlPdoSqlService}) keeps reading plain
 * `MYSQL_*` env vars and needs no Vault awareness.
 *
 * Wired into both entry points (`bin/ubix` and `public/index.php`) immediately
 * after Dotenv loads. It is a NO-OP unless `VAULT_ADDR` is set — so local
 * development keeps using a git-ignored `.env`, while deployed pods pull
 * credentials from Vault instead of from any committed secret file.
 *
 * Database credentials are read by default (`VAULT_DB_STRATEGY` unset or `kv`: a KV v2
 * secret at `VAULT_DB_KV_PATH`; `dynamic`: generated credentials), and a missing or empty
 * secret fails loudly. An app with no database at all opts out explicitly with
 * `VAULT_DB_STRATEGY=none`: the database read is skipped and only the app secrets
 * (`VAULT_APP_KV_PATH(S)`) are hydrated. It is never optional by default.
 *
 * Auth: prefers a static `VAULT_TOKEN` (dev/CI); otherwise, in-cluster, logs in
 * with the pod's mounted service-account JWT and `VAULT_K8S_ROLE` on the auth mount
 * named by `VAULT_AUTH_PATH` (default `kubernetes`; e.g. `jwt` for a vault that
 * validates several clusters' tokens by public key). A token obtained by logging in is revoked as soon as the
 * reads are done — it is not needed afterwards, and a token left to expire is a
 * live credential (and a stored record in the vault) until it does. A static
 * `VAULT_TOKEN` is never revoked: it is not this process's to end.
 *
 * Caching: given a cache (APCu in the entry points, see `Bootstrap/vault.php`),
 * the resolved environment is kept for `VAULT_CACHE_TTL` seconds (default 300;
 * 0 disables it), so a PHP-FPM pool logs in to the vault a few times an hour
 * instead of on every request, and a short vault outage does not take requests
 * down with it. Only a complete, successful resolution is cached, keyed on the
 * vault address and every setting that decides what is read; `dynamic` database
 * credentials are never cached (their lease belongs to the vault). A secret
 * rotated in the vault reaches the app within one TTL.
 *
 * @see \Ubix\Tests\Service\Vault\VaultCredentialResolverServiceTest PHPUnit test case
 */
final class VaultCredentialResolverService
{
    private const KUBERNETES_SERVICE_ACCOUNT_JWT = '/var/run/secrets/kubernetes.io/serviceaccount/token';

    /**
     * `VAULT_DB_STRATEGY` value for an app with no database: skip the credential read
     */
    private const STRATEGY_NONE = 'none';

    private const CACHE_KEY_PREFIX = 'vault.environment.';

    private const CACHE_TTL_DEFAULT = 300;

    /**
     * Map of Vault KV keys -> the environment variable each populates. The KV
     * secret at `VAULT_DB_KV_PATH` is expected to carry these keys.
     */
    private const KV_KEY_TO_ENV = [
        'read_password'  => 'MYSQL_READ_PASSWORD',
        'read_username'  => 'MYSQL_READ_USERNAME',
        'write_password' => 'MYSQL_WRITE_PASSWORD',
        'write_username' => 'MYSQL_WRITE_USERNAME',
    ];

    /**
     * Map of Vault KV keys -> the `TEST_MYSQL_WRITE_*` variable each populates.
     *
     * The unit-test connection is one credential pair, not a read/write split, so
     * the secret is flat: the whole connection lives in Vault rather than only its
     * password. A test database's host and name are not sensitive, but splitting
     * them across Vault and a committed file is how a `.env` ends up holding the
     * password anyway "just to keep them together".
     */
    private const TEST_KV_KEY_TO_ENV = [
        'database' => 'TEST_MYSQL_WRITE_DATABASE',
        'host'     => 'TEST_MYSQL_WRITE_HOST',
        'password' => 'TEST_MYSQL_WRITE_PASSWORD',
        'port'     => 'TEST_MYSQL_WRITE_PORT',
        'username' => 'TEST_MYSQL_WRITE_USERNAME',
    ];

    /**
     * Constructor
     *
     * @param Logger       $logger                  Logger
     * @param VaultService $vaultService            Client for the uBix Vault server
     * @param ?SimpleCache $cache                   Where the resolved environment is kept between requests; null for no cache
     * @param string       $serviceAccountTokenPath The pod's service-account JWT (for Kubernetes auth)
     */
    public function __construct(
        private Logger $logger,
        private VaultService $vaultService,
        private ?SimpleCache $cache = null,
        private string $serviceAccountTokenPath = self::KUBERNETES_SERVICE_ACCOUNT_JWT,
    ) {
    }

    /**
     * Resolve database credentials from Vault and inject them into the
     * environment via `putenv()`.
     *
     * With `VAULT_DB_STRATEGY=none` the database credentials are not read at all and
     * only the app secrets are hydrated.
     *
     * Authentication or read failure propagates as a RuntimeException from the
     * callees (fail-closed — when Vault is configured we never silently fall
     * back to potentially-stale environment values).
     *
     * @param string $vaultAddress Base address of the Vault server
     *
     * @return void
     */
    public function hydrateEnvironment(string $vaultAddress): void
    {
        $cacheKey = $this->cacheKey($vaultAddress);
        $cached   = $this->cachedEnvironment($cacheKey);

        if ($cached !== null) {
            $this->putEnvironment($cached);
            $this->logger->debug('Hydrated environment from the uBix Vault cache', ['vars' => array_keys($cached)]);

            return;
        }

        $skipDatabase = $this->readEnv('VAULT_DB_STRATEGY') === self::STRATEGY_NONE;

        if ($skipDatabase) {
            $this->logger->info('Skipping database credentials (VAULT_DB_STRATEGY=none)');

            if ($this->appSecretPaths() === []) {
                // Nothing to read: say so rather than logging in to the vault for nothing.
                $this->logger->warning('VAULT_DB_STRATEGY=none and no VAULT_APP_KV_PATH/VAULT_APP_KV_PATHS; nothing was hydrated from uBix Vault');

                return;
            }
        }

        // Resolve everything before touching the environment, so a failure part-way
        // (an app secret, after the database credentials) leaves it as it was.
        [$credentials, $appSecrets] = $this->withToken($vaultAddress, function (string $token) use ($vaultAddress, $skipDatabase): array {
            return [
                $skipDatabase ? [] : $this->resolveCredentials($vaultAddress, $token),
                $this->resolveAppSecrets($vaultAddress, $token),
            ];
        });

        if (!$skipDatabase) {
            $this->putEnvironment($credentials);
            $this->logger->info('Hydrated database credentials from uBix Vault', [
                'vars' => array_keys($credentials), // Names only — never the values.
            ]);
        }

        if ($appSecrets !== []) {
            $this->putEnvironment($appSecrets);
            $this->logger->info('Hydrated app secrets from uBix Vault', [
                'paths' => $this->appSecretPaths(),
                'vars'  => array_keys($appSecrets), // Names only — never the values.
            ]);
        }

        if ($this->readEnv('VAULT_DB_STRATEGY') !== 'dynamic') {
            $this->storeEnvironment($cacheKey, $credentials + $appSecrets);
        }
    }

    /**
     * Resolve the unit-test database connection from Vault into the environment
     *
     * Opt-in via `VAULT_TEST_DB_KV_PATH`, and a no-op without it — a host with no
     * Vault available keeps using its git-ignored `.env`, exactly as the runtime
     * credentials do.
     *
     * This exists so a developer machine does not need a database password in a
     * plaintext file at all. The remaining local secret is one Vault token, which
     * is revocable, scoped and expiring, where a copied `.env` password is none of
     * those things and is only ever rotated by remembering to.
     *
     * @param string $vaultAddress Base address of the Vault server
     *
     * @throws RuntimeException When the configured secret yields none of the expected keys
     *
     * @return void
     */
    public function hydrateTestDatabase(string $vaultAddress): void
    {
        $path = $this->readEnv('VAULT_TEST_DB_KV_PATH');

        if ($path === '') {
            return;
        }

        $secret   = $this->withToken($vaultAddress, function (string $token) use ($vaultAddress, $path): array {
            return $this->vaultService->readKvV2Secret($vaultAddress, $token, $path);
        });
        $resolved = [];

        foreach (self::TEST_KV_KEY_TO_ENV as $kvKey => $envName) {
            if (isset($secret[$kvKey]) && $secret[$kvKey] !== '') {
                $resolved[$envName] = (string) $secret[$kvKey];
            }
        }

        if ($resolved === []) {
            // Fail closed: a configured path that yields nothing means the secret is
            // wrong, and falling back to whatever the environment already held would
            // silently point the suite at another database.
            throw new RuntimeException(
                'uBix Vault KV secret `' . $path . '` (VAULT_TEST_DB_KV_PATH) had none of the expected keys (' . implode(', ', array_keys(self::TEST_KV_KEY_TO_ENV)) . ').',
            );
        }

        foreach ($resolved as $envName => $value) {
            putenv($envName . '=' . $value);
        }

        $this->logger->info('Hydrated test database connection from uBix Vault', [
            'vars' => array_keys($resolved), // Names only — never the values.
        ]);
    }

    /**
     * Hydrate the host application's own secrets (API tokens, webhook signing keys, ...)
     *
     * Opt-in via `VAULT_APP_KV_PATH` (one KV v2 path) and/or `VAULT_APP_KV_PATHS` (a
     * comma-separated list). Every key in those secrets whose name is an UPPER_SNAKE_CASE
     * environment-variable name becomes an environment variable, the same way the database
     * credentials do — so hosts never need a local shim, and never need a secret in source
     * or in a committed `.env`.
     *
     * Several paths exist so that each secret is stored once: an app that needs its own
     * keys *and* keys another app owns reads both paths instead of holding a second copy
     * that has to be rotated in step. `VAULT_APP_KV_PATH` is read first, then the list in
     * order. When two paths define the same key, **the first one wins** — list an app's own
     * path first and it can override a shared value — and the collision is logged by name.
     *
     * Keys in the `VAULT_*` and `MYSQL_*` namespaces are refused: those are this
     * resolver's own inputs and outputs, and an app secret must not be able to redirect
     * Vault auth or replace the database credentials resolved above.
     *
     * @param string $vaultAddress Base address of the Vault server
     * @param string $token        A valid Vault client token
     *
     * @return array<string, string> Environment variable name => value, empty when not configured
     *
     * @throws RuntimeException When a configured path yields no usable keys
     */
    private function resolveAppSecrets(string $vaultAddress, string $token): array
    {
        $paths    = $this->appSecretPaths();
        $hydrated = [];
        $values   = [];

        foreach ($paths as $path) {
            $usable = 0;

            foreach ($this->vaultService->readKvV2Secret($vaultAddress, $token, $path) as $key => $value) {
                if (
                    preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key) !== 1
                    || str_starts_with((string) $key, 'VAULT_')
                    || str_starts_with((string) $key, 'MYSQL_')
                    || $value === ''
                ) {
                    continue;
                }

                $usable++;

                if (isset($hydrated[$key])) {
                    $this->logger->warning('App secret defined in more than one uBix Vault path; the first wins', [
                        'kept' => $hydrated[$key],
                        'key'  => $key, // The name only — never the value.
                        'path' => $path,
                    ]);

                    continue;
                }

                $values[(string) $key] = $value;
                $hydrated[$key]        = $path;
            }

            if ($usable === 0) {
                // Fail closed, per path: a configured path that yields nothing means a
                // misconfigured or empty secret, and the app would otherwise boot without
                // the credentials that path was added for.
                throw new RuntimeException('uBix Vault KV secret `' . $path . '` (VAULT_APP_KV_PATH/VAULT_APP_KV_PATHS) yielded no usable UPPER_SNAKE_CASE keys.');
            }
        }

        return $values;
    }

    /**
     * The app-secret paths to read, in precedence order, without blanks or repeats
     *
     * @return list<string> `VAULT_APP_KV_PATH` first, then `VAULT_APP_KV_PATHS` in order
     */
    private function appSecretPaths(): array
    {
        $paths = [];

        foreach ([$this->readEnv('VAULT_APP_KV_PATH'), ...explode(',', $this->readEnv('VAULT_APP_KV_PATHS'))] as $path) {
            $path = trim($path);

            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Run $use with a Vault client token: a static VAULT_TOKEN when present,
     * otherwise a login with the pod's service-account JWT on the `VAULT_AUTH_PATH`
     * mount (default `kubernetes`) — which is revoked afterwards, whether $use
     * succeeded or not.
     *
     * A failed revocation is logged and otherwise ignored: the token still expires
     * on its own, and failing the request over it would trade a stray token for an
     * outage.
     *
     * @param string              $vaultAddress Base address of the Vault server
     * @param callable(string): T $use          What to do with the token
     *
     * @throws RuntimeException If no usable auth method is configured, or VAULT_AUTH_PATH is not one path segment
     *
     * @return T What $use returned
     *
     * @template T
     */
    private function withToken(string $vaultAddress, callable $use): mixed
    {
        $staticToken = $this->readEnv('VAULT_TOKEN');
        if ($staticToken !== '') {
            return $use($staticToken);
        }

        $role = $this->readEnv('VAULT_K8S_ROLE');
        if ($role === '' || !is_readable($this->serviceAccountTokenPath)) {
            throw new RuntimeException(
                'VAULT_ADDR is set but no auth is available: set VAULT_TOKEN, or VAULT_K8S_ROLE with a mounted service-account token.',
            );
        }

        $authPath = $this->readEnv('VAULT_AUTH_PATH');
        $jwt      = (string) file_get_contents($this->serviceAccountTokenPath);
        $token    = $this->vaultService->loginWithServiceAccountJwt(
            $vaultAddress,
            $authPath !== '' ? $authPath : VaultService::DEFAULT_AUTH_PATH,
            $role,
            trim($jwt),
        );

        try {
            return $use($token);
        } finally {
            try {
                $this->vaultService->revokeSelf($vaultAddress, $token);
            } catch (Throwable $exception) {
                $this->logger->warning('Could not revoke the uBix Vault login token; it will expire on its own', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Put each variable into the process environment
     *
     * MysqlPdoSqlService reads credentials via getenv(), so putenv() is sufficient —
     * no need to touch the $_ENV / $_SERVER superglobals.
     *
     * @param array<string, string> $environment Environment variable name => value
     *
     * @return void
     */
    private function putEnvironment(array $environment): void
    {
        foreach ($environment as $envName => $value) {
            putenv($envName . '=' . $value);
        }
    }

    /**
     * The cache key for this configuration: the vault address plus every setting
     * that decides what is read, hashed (no path or role appears in the key)
     *
     * @param string $vaultAddress Base address of the Vault server
     *
     * @return string A PSR-16-safe key
     */
    private function cacheKey(string $vaultAddress): string
    {
        $settings = [$vaultAddress];

        foreach (['VAULT_AUTH_PATH', 'VAULT_K8S_ROLE', 'VAULT_DB_STRATEGY', 'VAULT_DB_KV_PATH', 'VAULT_DB_ROLE', 'VAULT_APP_KV_PATH', 'VAULT_APP_KV_PATHS'] as $name) {
            $settings[] = $name . '=' . $this->readEnv($name);
        }

        return self::CACHE_KEY_PREFIX . hash('sha256', implode("\n", $settings));
    }

    /**
     * The cache lifetime in seconds from VAULT_CACHE_TTL (default 300; 0 disables)
     *
     * @return int Seconds; 0 when caching is off
     */
    private function cacheTtl(): int
    {
        $raw = $this->readEnv('VAULT_CACHE_TTL');

        if ($raw === '') {
            return self::CACHE_TTL_DEFAULT;
        }

        return ctype_digit($raw) ? (int) $raw : self::CACHE_TTL_DEFAULT;
    }

    /**
     * The cached environment for $key, or null on a miss, when caching is off, or
     * when the cache fails (a broken cache falls back to the vault, never errors)
     *
     * @param string $key Cache key
     *
     * @return array<string, string>|null Environment variable name => value
     */
    private function cachedEnvironment(string $key): ?array
    {
        if ($this->cache === null || $this->cacheTtl() === 0) {
            return null;
        }

        try {
            $value = $this->cache->get($key);
        } catch (Throwable $exception) {
            $this->logger->debug('Could not read the uBix Vault cache; resolving from the vault', ['error' => $exception->getMessage()]);

            return null;
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $environment = [];

        foreach ($value as $name => $item) {
            if (!is_string($name) || !is_string($item)) {
                return null;
            }

            $environment[$name] = $item;
        }

        return $environment;
    }

    /**
     * Keep a successfully resolved environment for the next requests
     *
     * @param string                $key         Cache key
     * @param array<string, string> $environment Environment variable name => value
     *
     * @return void
     */
    private function storeEnvironment(string $key, array $environment): void
    {
        if ($this->cache === null || $this->cacheTtl() === 0) {
            return;
        }

        try {
            $this->cache->set($key, $environment, $this->cacheTtl());
        } catch (Throwable $exception) {
            $this->logger->debug('Could not cache the uBix Vault environment', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Resolve the four MYSQL_* credential env values from Vault, using the
     * configured strategy (`kv` — a KV v2 secret, the default; or `dynamic` —
     * generated database credentials). `none` never reaches here: the caller skips
     * the database read for an app that has no database.
     *
     * @param string $vaultAddress Base address of the Vault server
     * @param string $token        A valid Vault client token
     *
     * @throws RuntimeException If the configured KV secret is missing the expected keys
     *
     * @return array<string, string> Map of MYSQL_* env var name => value
     */
    private function resolveCredentials(string $vaultAddress, string $token): array
    {
        $strategy = $this->readEnv('VAULT_DB_STRATEGY');

        if ($strategy === 'dynamic') {
            $role  = $this->readEnv('VAULT_DB_ROLE') !== '' ? $this->readEnv('VAULT_DB_ROLE') : 'app';
            $creds = $this->vaultService->readDatabaseCredentials($vaultAddress, $token, $role);

            // Dynamic role issues one credential pair; use it for both read + write.
            return [
                'MYSQL_READ_PASSWORD'  => $creds['password'],
                'MYSQL_READ_USERNAME'  => $creds['username'],
                'MYSQL_WRITE_PASSWORD' => $creds['password'],
                'MYSQL_WRITE_USERNAME' => $creds['username'],
            ];
        }

        $path   = $this->readEnv('VAULT_DB_KV_PATH') !== '' ? $this->readEnv('VAULT_DB_KV_PATH') : 'app/db';
        $secret = $this->vaultService->readKvV2Secret($vaultAddress, $token, $path);

        $resolved = [];
        foreach (self::KV_KEY_TO_ENV as $kvKey => $envName) {
            if (isset($secret[$kvKey]) && $secret[$kvKey] !== '') {
                $resolved[$envName] = $secret[$kvKey];
            }
        }

        if ($resolved === []) {
            throw new RuntimeException(
                'uBix Vault KV secret `' . $path . '` had none of the expected keys (' . implode(', ', array_keys(self::KV_KEY_TO_ENV)) . ').',
            );
        }

        return $resolved;
    }

    /**
     * Read an environment variable as a trimmed string (empty when unset).
     *
     * @param string $name Environment variable name
     *
     * @return string The value, or '' when unset/false
     */
    private function readEnv(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}
