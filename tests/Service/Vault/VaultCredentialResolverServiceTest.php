<?php

declare(strict_types=1);

namespace Ubix\Tests\Service\Vault;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Psr\SimpleCache\CacheInterface as SimpleCache;
use RuntimeException;
use Ubix\Service\JsonService;
use Ubix\Service\Vault\VaultCredentialResolverService;
use Ubix\Service\Vault\VaultService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Service\Vault\VaultCredentialResolverService
 *
 * @coversDefaultClass \Ubix\Service\Vault\VaultCredentialResolverService
 */
final class VaultCredentialResolverServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const ENV = [
        'VAULT_TOKEN', 'VAULT_K8S_ROLE', 'VAULT_DB_KV_PATH', 'VAULT_APP_KV_PATH', 'VAULT_APP_KV_PATHS', 'VAULT_DB_STRATEGY',
        'VAULT_DB_ROLE', 'VAULT_TEST_DB_KV_PATH',
        'MYSQL_READ_USERNAME', 'MYSQL_READ_PASSWORD', 'MYSQL_WRITE_USERNAME', 'MYSQL_WRITE_PASSWORD',
        'TEST_MYSQL_WRITE_HOST', 'TEST_MYSQL_WRITE_PORT', 'TEST_MYSQL_WRITE_DATABASE',
        'TEST_MYSQL_WRITE_USERNAME', 'TEST_MYSQL_WRITE_PASSWORD',
        'API_BEARER_TOKENS', 'lower_case_key', 'VAULT_ADDR_OVERRIDE', 'OWN_KEY', 'SHARED_KEY', 'OTHER_KEY',
        'VAULT_CACHE_TTL', 'VAULT_AUTH_PATH',
    ];

    private const VAULT_ADDRESS = 'https://vault.test';

    private const FULL_DB_SECRET = [
        'read_password'  => 'read-pass',
        'read_username'  => 'reader',
        'write_password' => 'write-pass',
        'write_username' => 'writer',
    ];

    /**
     * Values the managed env vars held before the test (false = unset).
     *
     * @var array<string, string|false>
     */
    private array $originalEnv = [];

    /**
     * A service-account JWT file for the Kubernetes-auth tests
     */
    private string $jwtPath = '';

    /**
     * Requests the resolver's VaultService sent, in order.
     *
     * @var array<int, Request>
     */
    private array $requests = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(VaultCredentialResolverService::class);
    }

    /**
     * Without VAULT_APP_KV_PATH only the database credentials are hydrated
     *
     * @return void
     */
    public function testAppSecretsAreOptIn(): void
    {
        putenv('VAULT_TOKEN=test-token');

        $this->resolver([$this->kv(['read_username' => 'r', 'read_password' => 'rp', 'write_username' => 'w', 'write_password' => 'wp'])])
            ->hydrateEnvironment('https://vault.test');

        $this->assertSame('r', getenv('MYSQL_READ_USERNAME'));
        $this->assertFalse(getenv('API_BEARER_TOKENS'));
    }

    /**
     * UPPER_SNAKE_CASE keys become env vars; lower-case and VAULT_* / MYSQL_* keys are refused
     *
     * @return void
     */
    public function testHydratesUpperSnakeKeysAndRefusesReservedNamespaces(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/api');

        $this->resolver([
            $this->kv(['read_username' => 'r', 'read_password' => 'rp', 'write_username' => 'w', 'write_password' => 'wp']),
            $this->kv([
                'API_BEARER_TOKENS'    => 'one,two',
                'lower_case_key'       => 'ignored',
                'MYSQL_WRITE_PASSWORD' => 'hijacked',
                'VAULT_ADDR_OVERRIDE'  => 'https://attacker.test',
            ]),
        ])->hydrateEnvironment('https://vault.test');

        $this->assertSame('one,two', getenv('API_BEARER_TOKENS'));
        $this->assertFalse(getenv('lower_case_key'));
        $this->assertFalse(getenv('VAULT_ADDR_OVERRIDE'));
        $this->assertSame('wp', getenv('MYSQL_WRITE_PASSWORD'), 'An app secret must not replace the resolved DB credentials');
    }

    /**
     * A configured app path that yields nothing usable fails closed
     *
     * @return void
     */
    public function testFailsClosedWhenAppSecretIsEmpty(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/api');

        $this->expectException(RuntimeException::class);

        $this->resolver([
            $this->kv(['read_username' => 'r', 'read_password' => 'rp', 'write_username' => 'w', 'write_password' => 'wp']),
            $this->kv(['lower_case_only' => 'x']),
        ])->hydrateEnvironment('https://vault.test');
    }

    /**
     * The test-database hydration is opt-in and silent without its path
     *
     * @return void
     */
    public function testTestDatabaseHydrationIsOptIn(): void
    {
        putenv('VAULT_TOKEN=test-token');

        // No VAULT_TEST_DB_KV_PATH: no HTTP call is queued, so reaching one would
        // fail the mock handler rather than pass quietly.
        $this->resolver([])->hydrateTestDatabase('https://vault.test');

        $this->assertFalse(getenv('TEST_MYSQL_WRITE_HOST'));
    }

    /**
     * The whole test connection comes from Vault, so no file needs the password
     *
     * @return void
     */
    public function testTestDatabaseConnectionIsHydratedFromVault(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_TEST_DB_KV_PATH=ubixcore/test-db');

        $this->resolver([
            $this->kv([
                'database' => 'ubixcore_test',
                'host'     => 'db.internal',
                'password' => 'secret',
                'port'     => '30306',
                'username' => 'ubixcore_test',
            ]),
        ])->hydrateTestDatabase('https://vault.test');

        $this->assertSame('db.internal', getenv('TEST_MYSQL_WRITE_HOST'));
        $this->assertSame('30306', getenv('TEST_MYSQL_WRITE_PORT'));
        $this->assertSame('ubixcore_test', getenv('TEST_MYSQL_WRITE_DATABASE'));
        $this->assertSame('ubixcore_test', getenv('TEST_MYSQL_WRITE_USERNAME'));
        $this->assertSame('secret', getenv('TEST_MYSQL_WRITE_PASSWORD'));
    }

    /**
     * Vault wins over a stale value the environment already carried
     *
     * @return void
     */
    public function testVaultOverridesAnExistingEnvironmentValue(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_TEST_DB_KV_PATH=ubixcore/test-db');
        putenv('TEST_MYSQL_WRITE_HOST=127.0.0.1');

        $this->resolver([$this->kv(['host' => 'db.internal', 'password' => 'secret'])])
            ->hydrateTestDatabase('https://vault.test');

        // The retired sandbox host is exactly the stale value this replaces.
        $this->assertSame('db.internal', getenv('TEST_MYSQL_WRITE_HOST'));
    }

    /**
     * A configured test-db path that yields nothing usable fails closed
     *
     * @return void
     */
    public function testFailsClosedWhenTestDatabaseSecretIsEmpty(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_TEST_DB_KV_PATH=ubixcore/test-db');

        $this->expectException(RuntimeException::class);

        // Falling back to whatever the environment held would point the suite at
        // another database without saying so.
        $this->resolver([$this->kv(['unexpected' => 'x'])])->hydrateTestDatabase('https://vault.test');
    }

    /**
     * A static VAULT_TOKEN (trimmed) reads the default `app/db` KV path into all four MYSQL_* variables
     *
     * @return void
     */
    public function testStaticTokenHydratesAllDatabaseCredentialsFromDefaultPath(): void
    {
        putenv("VAULT_TOKEN=  test-token\n");

        $this->resolver([$this->kv(self::FULL_DB_SECRET)])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('reader', getenv('MYSQL_READ_USERNAME'));
        $this->assertSame('read-pass', getenv('MYSQL_READ_PASSWORD'));
        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
        $this->assertSame('write-pass', getenv('MYSQL_WRITE_PASSWORD'));

        $this->assertCount(1, $this->requests, 'A static token needs no login call');
        $request = $this->requests[0];
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/db', (string) $request->getUri());
        $this->assertSame('test-token', $request->getHeaderLine('X-Vault-Token'));
    }

    /**
     * VAULT_DB_KV_PATH selects which KV secret holds the credentials
     *
     * @return void
     */
    public function testKvPathIsConfigurable(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_KV_PATH=kitg/db-prod');

        $this->resolver([$this->kv(self::FULL_DB_SECRET)])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/kitg/db-prod', (string) $this->requests[0]->getUri());
    }

    /**
     * Keys missing or empty in the secret leave their variables as they were
     *
     * @return void
     */
    public function testPartialSecretOnlyOverwritesTheKeysItCarries(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('MYSQL_WRITE_USERNAME=env-writer');
        putenv('MYSQL_WRITE_PASSWORD=env-write-pass');

        $this->resolver([$this->kv(['read_username' => 'reader', 'read_password' => 'read-pass', 'write_password' => ''])])
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('reader', getenv('MYSQL_READ_USERNAME'));
        $this->assertSame('read-pass', getenv('MYSQL_READ_PASSWORD'));
        $this->assertSame('env-writer', getenv('MYSQL_WRITE_USERNAME'));
        $this->assertSame('env-write-pass', getenv('MYSQL_WRITE_PASSWORD'), 'An empty Vault value must not blank a credential');
    }

    /**
     * A KV secret with none of the expected keys fails closed and changes nothing
     *
     * @return void
     */
    public function testFailsClosedWhenDatabaseSecretHasNoExpectedKeys(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('MYSQL_READ_PASSWORD=stale');

        try {
            $this->resolver([$this->kv(['username' => 'wrong-shape', 'password' => 'x'])])->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected a RuntimeException for a secret with none of the expected keys');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('`app/db` had none of the expected keys', $exception->getMessage());
        }

        $this->assertSame('stale', getenv('MYSQL_READ_PASSWORD'));
    }

    /**
     * The dynamic strategy reads the default `app` role and uses its one pair for read and write
     *
     * @return void
     */
    public function testDynamicStrategyUsesOneGeneratedPairForReadAndWrite(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=dynamic');

        $this->resolver([$this->creds('v-app-123', 'generated')])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame(self::VAULT_ADDRESS . '/v1/database/creds/app', (string) $this->requests[0]->getUri());
        $this->assertSame('v-app-123', getenv('MYSQL_READ_USERNAME'));
        $this->assertSame('v-app-123', getenv('MYSQL_WRITE_USERNAME'));
        $this->assertSame('generated', getenv('MYSQL_READ_PASSWORD'));
        $this->assertSame('generated', getenv('MYSQL_WRITE_PASSWORD'));
    }

    /**
     * VAULT_DB_ROLE selects the dynamic database role
     *
     * @return void
     */
    public function testDynamicStrategyRoleIsConfigurable(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=dynamic');
        putenv('VAULT_DB_ROLE=kitg-api');

        $this->resolver([$this->creds('v-kitg', 'p')])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame(self::VAULT_ADDRESS . '/v1/database/creds/kitg-api', (string) $this->requests[0]->getUri());
    }

    /**
     * With neither a token nor a Kubernetes role, the resolver refuses before contacting Vault
     *
     * @return void
     */
    public function testThrowsWithoutAnyAuthBeforeContactingVault(): void
    {
        try {
            $this->resolver([])->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected a RuntimeException with no auth configured');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no auth is available', $exception->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    /**
     * A Vault error propagates and leaves the existing credentials in place (fail closed)
     *
     * @return void
     */
    public function testVaultErrorPropagatesAndLeavesEnvironmentUntouched(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('MYSQL_WRITE_PASSWORD=stale');

        try {
            $this->resolver([new Response(403, [], '{"errors":["permission denied"]}')])->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected the Vault 403 to propagate');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed', $exception->getMessage());
        }

        $this->assertSame('stale', getenv('MYSQL_WRITE_PASSWORD'));
    }

    /**
     * The success log names the hydrated variables and never carries their values
     *
     * @return void
     */
    public function testLogsVariableNamesButNeverValues(): void
    {
        putenv('VAULT_TOKEN=test-token');

        // An exact context match: names only, so no value can ride along.
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Hydrated database credentials from uBix Vault',
                ['vars' => ['MYSQL_READ_PASSWORD', 'MYSQL_READ_USERNAME', 'MYSQL_WRITE_PASSWORD', 'MYSQL_WRITE_USERNAME']],
            );

        $this->resolver([$this->kv(self::FULL_DB_SECRET)], $logger)->hydrateEnvironment(self::VAULT_ADDRESS);
    }

    /**
     * VAULT_DB_STRATEGY=none skips the database read entirely and still hydrates the app secrets
     *
     * @return void
     */
    public function testNoneStrategySkipsTheDatabaseReadAndStillHydratesAppSecrets(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=none');
        putenv('VAULT_APP_KV_PATH=app/api');

        $this->resolver([$this->kv(['API_BEARER_TOKENS' => 'one'])])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(1, $this->requests, 'Only the app secret is read');
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/api', (string) $this->requests[0]->getUri());
        $this->assertSame('one', getenv('API_BEARER_TOKENS'));
        $this->assertFalse(getenv('MYSQL_READ_USERNAME'));
        $this->assertFalse(getenv('MYSQL_WRITE_PASSWORD'));
    }

    /**
     * With none, a database path that would be forbidden is never touched, so it cannot fail the start
     *
     * @return void
     */
    public function testNoneStrategyDoesNotFailWhenTheDatabasePathIsForbidden(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=none');
        putenv('VAULT_DB_KV_PATH=app/db');
        putenv('VAULT_APP_KV_PATH=app/api');

        // Only one response is queued: a second read would exhaust the mock and throw.
        $this->resolver([$this->kv(['API_BEARER_TOKENS' => 'one'])])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('one', getenv('API_BEARER_TOKENS'));
        foreach ($this->requests as $request) {
            $this->assertStringNotContainsString('app/db', (string) $request->getUri());
        }
    }

    /**
     * With none and no app secret paths nothing is contacted, a warning is logged, and nothing is set
     *
     * @return void
     */
    public function testNoneStrategyWithoutAppPathsWarnsAndHydratesNothing(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=none');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('nothing was hydrated'));

        $this->resolver([], $logger)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(0, $this->requests);
        $this->assertFalse(getenv('MYSQL_READ_USERNAME'));
    }

    /**
     * Without VAULT_DB_STRATEGY the database read is still required: a forbidden or empty secret fails loudly
     *
     * @return void
     */
    public function testDatabaseReadIsNotOptionalByDefault(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/api');

        $this->expectException(RuntimeException::class);

        $this->resolver([$this->kv(['unexpected' => 'x']), $this->kv(['API_BEARER_TOKENS' => 'one'])])
            ->hydrateEnvironment(self::VAULT_ADDRESS);
    }

    /**
     * An explicit `kv` reads the KV secret exactly as unset does (the refusal message tells people to use it)
     *
     * @return void
     */
    public function testExplicitKvStrategyReadsTheKvSecret(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=kv');

        $this->resolver([$this->kv(self::FULL_DB_SECRET)])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/db', (string) $this->requests[0]->getUri());
    }

    /**
     * An unknown VAULT_DB_STRATEGY is refused by name before the vault is contacted, not run as `kv`
     *
     * @param string $strategy The unknown value
     *
     * @return void
     */
    #[DataProvider('unknownStrategies')]
    public function testUnknownStrategyIsRefusedByName(string $strategy): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=' . $strategy);
        putenv('MYSQL_WRITE_PASSWORD=stale');

        try {
            $this->resolver([$this->kv(self::FULL_DB_SECRET)], null, $this->arrayCache())->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected VAULT_DB_STRATEGY=' . $strategy . ' to be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('VAULT_DB_STRATEGY=`' . $strategy . '` is not a known strategy', $exception->getMessage());
        }

        $this->assertSame([], $this->requests, 'Nothing is read from the vault');
        $this->assertSame('stale', getenv('MYSQL_WRITE_PASSWORD'), 'The environment is left as it was');
    }

    /**
     * Cache entries made under none are kept apart from the default strategy's (the key includes the strategy)
     *
     * @return void
     */
    public function testNoneStrategyIsCachedSeparatelyFromTheDefault(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/api');
        $cache = $this->arrayCache();

        putenv('VAULT_DB_STRATEGY=none');
        $this->resolver([$this->kv(['API_BEARER_TOKENS' => 'one'])], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        putenv('VAULT_DB_STRATEGY');
        $this->resolver([$this->kv(self::FULL_DB_SECRET), $this->kv(['API_BEARER_TOKENS' => 'one'])], null, $cache)
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(2, $this->requests, 'The default strategy must not reuse the none entry');
        $this->assertSame('reader', getenv('MYSQL_READ_USERNAME'));
    }

    /**
     * App secrets are read with the same token and after the database credentials
     *
     * @return void
     */
    public function testAppSecretsAreReadAfterDatabaseCredentialsWithTheSameToken(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/api');

        $this->resolver([$this->kv(self::FULL_DB_SECRET), $this->kv(['API_BEARER_TOKENS' => 'one'])])
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(2, $this->requests);
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/db', (string) $this->requests[0]->getUri());
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/api', (string) $this->requests[1]->getUri());
        $this->assertSame('test-token', $this->requests[1]->getHeaderLine('X-Vault-Token'));
    }

    /**
     * VAULT_APP_KV_PATH then VAULT_APP_KV_PATHS are read in order, repeats skipped, and the first definition of a key wins
     *
     * @return void
     */
    public function testReadsSeveralAppPathsInOrderAndTheFirstDefinitionWins(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/admin');
        putenv('VAULT_APP_KV_PATHS= app/api , app/admin,');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('more than one'), $this->callback(
                static function (array $context): bool {
                    return $context['key'] === 'SHARED_KEY' && $context['kept'] === 'app/admin' && !in_array('from-api', $context, true);
                },
            ));

        $this->resolver([
            $this->kv(self::FULL_DB_SECRET),
            $this->kv(['OWN_KEY' => 'own', 'SHARED_KEY' => 'from-admin']),
            $this->kv(['SHARED_KEY' => 'from-api', 'OTHER_KEY' => 'other']),
        ], $logger)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(3, $this->requests, 'app/admin is listed twice but read once');
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/admin', (string) $this->requests[1]->getUri());
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/api', (string) $this->requests[2]->getUri());
        $this->assertSame('own', getenv('OWN_KEY'));
        $this->assertSame('other', getenv('OTHER_KEY'));
        $this->assertSame('from-admin', getenv('SHARED_KEY'), 'The first path to define a key wins');
    }

    /**
     * VAULT_APP_KV_PATHS works without VAULT_APP_KV_PATH
     *
     * @return void
     */
    public function testPathsListWorksOnItsOwn(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATHS=app/api');

        $this->resolver([$this->kv(self::FULL_DB_SECRET), $this->kv(['API_BEARER_TOKENS' => 'one'])])
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('one', getenv('API_BEARER_TOKENS'));
    }

    /**
     * Each configured path must yield something: a later empty path fails closed even when an earlier one did not
     *
     * @return void
     */
    public function testFailsClosedWhenAnyListedPathIsEmpty(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATHS=app/admin,app/api');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('app/api');

        $this->resolver([
            $this->kv(self::FULL_DB_SECRET),
            $this->kv(['OWN_KEY' => 'own']),
            $this->kv(['lower_case_only' => 'x']),
        ])->hydrateEnvironment(self::VAULT_ADDRESS);
    }

    /**
     * A token obtained by Kubernetes login is revoked once the reads are done
     *
     * @return void
     */
    public function testKubernetesLoginTokenIsRevokedAfterTheReads(): void
    {
        putenv('VAULT_K8S_ROLE=app');

        $this->resolver([$this->json(['auth' => ['client_token' => 'hvs.minted']]), $this->kv(self::FULL_DB_SECRET), new Response(204)])
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
        $this->assertCount(3, $this->requests);
        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/token/revoke-self', (string) $this->requests[2]->getUri());
        $this->assertSame('hvs.minted', $this->requests[2]->getHeaderLine('X-Vault-Token'));
    }

    /**
     * The minted token is revoked even when a read fails, and the read's error still propagates
     *
     * @return void
     */
    public function testKubernetesLoginTokenIsRevokedEvenWhenAReadFails(): void
    {
        putenv('VAULT_K8S_ROLE=app');

        try {
            $this->resolver([
                $this->json(['auth' => ['client_token' => 'hvs.minted']]),
                new Response(403, [], '{"errors":["permission denied"]}'),
                new Response(204),
            ])->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected the read failure to propagate');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('secret/data/app/db', $exception->getMessage());
        }

        $this->assertCount(3, $this->requests);
        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/token/revoke-self', (string) $this->requests[2]->getUri());
    }

    /**
     * Without VAULT_AUTH_PATH the service-account login goes to the `kubernetes` mount
     *
     * @return void
     */
    public function testServiceAccountLoginDefaultsToTheKubernetesMount(): void
    {
        putenv('VAULT_K8S_ROLE=app');

        $this->resolver([$this->json(['auth' => ['client_token' => 'hvs.minted']]), $this->kv(self::FULL_DB_SECRET), new Response(204)])
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/kubernetes/login', (string) $this->requests[0]->getUri());
    }

    /**
     * VAULT_AUTH_PATH selects the mount, e.g. a `jwt` mount, with the same role and JWT
     *
     * @return void
     */
    public function testAuthPathSelectsTheLoginMount(): void
    {
        putenv('VAULT_K8S_ROLE=app');
        putenv('VAULT_AUTH_PATH=jwt');

        $this->resolver([$this->json(['auth' => ['client_token' => 'hvs.minted']]), $this->kv(self::FULL_DB_SECRET), new Response(204)])
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/jwt/login', (string) $this->requests[0]->getUri());
        $this->assertSame('{"jwt":"the.pod.jwt","role":"app"}', (string) $this->requests[0]->getBody());
        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
    }

    /**
     * A VAULT_AUTH_PATH that is not one path segment fails before the vault is contacted
     *
     * @return void
     */
    public function testUnsafeAuthPathFailsBeforeContactingVault(): void
    {
        putenv('VAULT_K8S_ROLE=app');
        putenv('VAULT_AUTH_PATH=../../sys');

        try {
            $this->resolver([$this->json(['auth' => ['client_token' => 'hvs.minted']])])->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected an unsafe VAULT_AUTH_PATH to be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('`../../sys`', $exception->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    /**
     * A failed revocation is logged and does not fail the request
     *
     * @return void
     */
    public function testRevocationFailureIsLoggedAndDoesNotFailTheRequest(): void
    {
        putenv('VAULT_K8S_ROLE=app');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('Could not revoke'));

        $this->resolver([
            $this->json(['auth' => ['client_token' => 'hvs.minted']]),
            $this->kv(self::FULL_DB_SECRET),
            new Response(500, [], '{"errors":["boom"]}'),
        ], $logger)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
    }

    /**
     * A static VAULT_TOKEN is never revoked: it is not this process's to end
     *
     * @return void
     */
    public function testStaticTokenIsNeverRevoked(): void
    {
        putenv('VAULT_TOKEN=ci-token');

        $this->resolver([$this->kv(self::FULL_DB_SECRET)])->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(1, $this->requests);
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/db', (string) $this->requests[0]->getUri());
    }

    /**
     * A resolved environment is cached and the next request needs no vault call at all
     *
     * @return void
     */
    public function testResolvedEnvironmentIsCachedAndReused(): void
    {
        putenv('VAULT_K8S_ROLE=app');
        putenv('VAULT_APP_KV_PATH=app/api');
        $cache = $this->arrayCache();

        $this->resolver([
            $this->json(['auth' => ['client_token' => 'hvs.minted']]),
            $this->kv(self::FULL_DB_SECRET),
            $this->kv(['API_BEARER_TOKENS' => 'one']),
            new Response(204),
        ], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        foreach (['MYSQL_WRITE_USERNAME', 'MYSQL_WRITE_PASSWORD', 'API_BEARER_TOKENS'] as $name) {
            putenv($name);
        }

        // An empty response queue: any vault request would fail the test.
        $this->resolver([], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(0, $this->requests);
        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
        $this->assertSame('write-pass', getenv('MYSQL_WRITE_PASSWORD'));
        $this->assertSame('one', getenv('API_BEARER_TOKENS'));
    }

    /**
     * VAULT_CACHE_TTL=0 turns the cache off
     *
     * @return void
     */
    public function testCacheTtlZeroDisablesTheCache(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_CACHE_TTL=0');
        $cache = $this->arrayCache();

        $this->resolver([$this->kv(self::FULL_DB_SECRET)], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);
        $this->resolver([$this->kv(self::FULL_DB_SECRET)], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(1, $this->requests, 'the second resolution went to the vault');
    }

    /**
     * Dynamic database credentials are never cached (their lease belongs to the vault)
     *
     * @return void
     */
    public function testDynamicCredentialsAreNotCached(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_DB_STRATEGY=dynamic');
        $cache = $this->arrayCache();

        $this->resolver([$this->creds('dyn-user', 'dyn-pass')], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);
        $this->resolver([$this->creds('dyn-user2', 'dyn-pass2')], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(1, $this->requests);
        $this->assertSame('dyn-user2', getenv('MYSQL_WRITE_USERNAME'));
    }

    /**
     * A change to what is read (here the KV path) is a cache miss, not a stale hit
     *
     * @return void
     */
    public function testCacheIsKeyedOnConfiguration(): void
    {
        putenv('VAULT_TOKEN=test-token');
        $cache = $this->arrayCache();

        $this->resolver([$this->kv(self::FULL_DB_SECRET)], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        putenv('VAULT_DB_KV_PATH=other/db');
        $this->resolver([$this->kv(['write_username' => 'other-writer', 'write_password' => 'x'])], null, $cache)
            ->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(1, $this->requests);
        $this->assertSame('other-writer', getenv('MYSQL_WRITE_USERNAME'));
    }

    /**
     * A failed resolution is not cached
     *
     * @return void
     */
    public function testFailuresAreNotCached(): void
    {
        putenv('VAULT_TOKEN=test-token');
        $cache = $this->arrayCache();

        try {
            $this->resolver([new Response(403, [], '{"errors":["permission denied"]}')], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->resolver([$this->kv(self::FULL_DB_SECRET)], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertCount(1, $this->requests, 'the retry went to the vault');
        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
    }

    /**
     * A cache that throws falls back to the vault rather than failing the request
     *
     * @return void
     */
    public function testBrokenCacheFallsBackToTheVault(): void
    {
        putenv('VAULT_TOKEN=test-token');
        $cache = $this->createStub(SimpleCache::class);
        $cache->method('get')->willThrowException(new RuntimeException('cache down'));
        $cache->method('set')->willThrowException(new RuntimeException('cache down'));

        $this->resolver([$this->kv(self::FULL_DB_SECRET)], null, $cache)->hydrateEnvironment(self::VAULT_ADDRESS);

        $this->assertSame('writer', getenv('MYSQL_WRITE_USERNAME'));
    }

    /**
     * An app-secret failure leaves the environment untouched: nothing is applied until everything resolved
     *
     * @return void
     */
    public function testAppSecretFailureLeavesTheEnvironmentUntouched(): void
    {
        putenv('VAULT_TOKEN=test-token');
        putenv('VAULT_APP_KV_PATH=app/api');
        putenv('MYSQL_WRITE_PASSWORD=stale');

        try {
            $this->resolver([$this->kv(self::FULL_DB_SECRET), $this->kv(['lower_case_only' => 'x'])])->hydrateEnvironment(self::VAULT_ADDRESS);
            $this->fail('Expected the empty app secret to fail closed');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame('stale', getenv('MYSQL_WRITE_PASSWORD'));
    }

    /**
     * VAULT_DB_STRATEGY values that are not `kv`, `dynamic` or `none`
     *
     * @return array<string, array{0: string}>
     */
    public static function unknownStrategies(): array
    {
        return [
            'capitalised dynamic' => ['Dynamic'],
            'capitalised none'    => ['None'],
            'kv2'                 => ['kv2'],
            'typo'                => ['dynmic'],
            'unrelated'           => ['vault'],
        ];
    }

    /**
     * Capture then clear every env var these tests can set, so a developer's own
     * VAULT_* / MYSQL_* shell variables neither leak in nor get wiped
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }

        $this->jwtPath = (string) tempnam(sys_get_temp_dir(), 'sa-jwt-');
        file_put_contents($this->jwtPath, "the.pod.jwt\n");
    }

    /**
     * Restore every env var these tests can set
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        if ($this->jwtPath !== '' && is_file($this->jwtPath)) {
            unlink($this->jwtPath);
        }

        parent::tearDown();
    }

    /**
     * Build a resolver over a real VaultService whose HTTP client replays the given responses
     *
     * @param array<int, Response> $responses Queued responses, in request order
     * @param ?Logger              $logger    Logger to inject, or a stub when null
     * @param ?SimpleCache         $cache     Cache for the resolved environment (null for none)
     *
     * @return VaultCredentialResolverService
     */
    private function resolver(array $responses, ?Logger $logger = null, ?SimpleCache $cache = null): VaultCredentialResolverService
    {
        $logger ??= $this->createStub(Logger::class);

        $this->requests = [];
        $stack          = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::mapRequest(function (Request $request): Request {
            $this->requests[] = $request;

            return $request;
        }));
        $client = new Client(['handler' => $stack]);

        return new VaultCredentialResolverService($logger, new VaultService($logger, $client, new JsonService($logger)), $cache, $this->jwtPath);
    }

    /**
     * A KV v2 read response carrying the given data map
     *
     * @param array<string, string> $data Secret key/value pairs
     *
     * @return Response
     */
    private function kv(array $data): Response
    {
        $json = new JsonService($this->createStub(Logger::class));

        return new Response(200, ['Content-Type' => 'application/json'], $json->encode(['data' => ['data' => $data]]));
    }

    /**
     * A dynamic database-credentials response carrying the given pair
     *
     * @param string $username Generated username
     * @param string $password Generated password
     *
     * @return Response
     */
    private function creds(string $username, string $password): Response
    {
        $json = new JsonService($this->createStub(Logger::class));

        return new Response(200, ['Content-Type' => 'application/json'], $json->encode(['data' => ['username' => $username, 'password' => $password]]));
    }

    /**
     * A JSON response
     *
     * @param array<string, mixed> $body Response body
     *
     * @return Response
     */
    private function json(array $body): Response
    {
        $json = new JsonService($this->createStub(Logger::class));

        return new Response(200, ['Content-Type' => 'application/json'], $json->encode($body));
    }

    /**
     * An in-memory PSR-16 cache standing in for APCu (get and set are all the resolver uses)
     *
     * @return SimpleCache
     */
    private function arrayCache(): SimpleCache
    {
        $items = new ArrayObject();
        $cache = $this->createStub(SimpleCache::class);
        $cache->method('get')->willReturnCallback(function (string $key, mixed $default = null) use ($items): mixed {
            return $items[$key] ?? $default;
        });
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value) use ($items): bool {
            $items[$key] = $value;

            return true;
        });

        return $cache;
    }
}
