<?php

declare(strict_types=1);

namespace Ubix\Tests\Service\Vault;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Service\JsonService;
use Ubix\Service\Vault\VaultService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Service\Vault\VaultService
 *
 * Drives the real client through a Guzzle MockHandler with a recording
 * middleware, so every test asserts the exact HTTP request Vault would see
 * (method, URL, headers, body) as well as how the response is interpreted.
 *
 * @coversDefaultClass \Ubix\Service\Vault\VaultService
 */
final class VaultServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const VAULT_ADDRESS = 'https://vault.test:8200';

    private const TOKEN = 'hvs.test-token';

    /**
     * Requests the client sent, in order, as recorded by a mapRequest middleware.
     *
     * @var array<int, Request>
     */
    private array $requests = [];

    /**
     * The mock handler behind the last-built service, for the options it last received.
     */
    private ?MockHandler $handler = null;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(VaultService::class);
    }

    /**
     * Test that revokeSelf posts to revoke-self with the token, and accepts the empty 204
     *
     * @return void
     */
    public function testRevokeSelfPostsWithTheTokenAndAcceptsAnEmptyResponse(): void
    {
        $this->service([new Response(204)])->revokeSelf(self::VAULT_ADDRESS, 'hvs.minted');

        $request = $this->onlyRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/token/revoke-self', (string) $request->getUri());
        $this->assertSame('hvs.minted', $request->getHeaderLine('X-Vault-Token'));
    }

    /**
     * Test that a failed revocation surfaces as a RuntimeException for the caller to decide on
     *
     * @return void
     */
    public function testRevokeSelfFailureThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service([new Response(403, [], '{"errors":["permission denied"]}')])->revokeSelf(self::VAULT_ADDRESS, 'hvs.minted');
    }

    /**
     * Kubernetes login POSTs the JWT + role unauthenticated and returns the client token
     *
     * @return void
     */
    public function testLoginKubernetesPostsJwtAndRoleAndReturnsClientToken(): void
    {
        $token = $this->service([$this->json(['auth' => ['client_token' => 'hvs.issued']])])
            ->loginKubernetes(self::VAULT_ADDRESS . '/', 'kitg-api', 'the.jwt.value');

        $this->assertSame('hvs.issued', $token);

        $request = $this->onlyRequest();
        $this->assertSame('POST', $request->getMethod());
        // The trailing slash on the address is trimmed, not doubled.
        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/kubernetes/login', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertFalse($request->hasHeader('X-Vault-Token'), 'Login is unauthenticated; no token header is sent');
        $this->assertSame(['jwt' => 'the.jwt.value', 'role' => 'kitg-api'], $this->decodeBody($request));
    }

    /**
     * A login response without a usable client_token is refused
     *
     * @param array<string, mixed> $body The decoded login response
     *
     * @return void
     */
    #[DataProvider('loginResponsesWithoutToken')]
    public function testLoginKubernetesThrowsWithoutClientToken(array $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a client_token');

        $this->service([$this->json($body)])->loginKubernetes(self::VAULT_ADDRESS, 'kitg-api', 'jwt');
    }

    /**
     * A service-account login on another mount POSTs the same body to /v1/auth/<mount>/login
     *
     * @return void
     */
    public function testLoginWithServiceAccountJwtUsesTheGivenMount(): void
    {
        $token = $this->service([$this->json(['auth' => ['client_token' => 'hvs.issued']])])
            ->loginWithServiceAccountJwt(self::VAULT_ADDRESS, 'jwt', 'kitg-api', 'the.jwt.value');

        $this->assertSame('hvs.issued', $token);

        $request = $this->onlyRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::VAULT_ADDRESS . '/v1/auth/jwt/login', (string) $request->getUri());
        $this->assertFalse($request->hasHeader('X-Vault-Token'), 'Login is unauthenticated; no token header is sent');
        $this->assertSame(['jwt' => 'the.jwt.value', 'role' => 'kitg-api'], $this->decodeBody($request));
    }

    /**
     * A mount that is not one safe path segment is refused before any request is sent
     *
     * @param string $authPath The rejected mount
     *
     * @return void
     */
    #[DataProvider('unsafeAuthPaths')]
    public function testLoginWithServiceAccountJwtRefusesAnUnsafeMount(string $authPath): void
    {
        try {
            $this->service([$this->json(['auth' => ['client_token' => 'hvs.issued']])])
                ->loginWithServiceAccountJwt(self::VAULT_ADDRESS, $authPath, 'kitg-api', 'jwt');
            $this->fail('Expected an unsafe mount to be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('VAULT_AUTH_PATH must be a single path segment', $exception->getMessage());
            $this->assertStringContainsString('`' . $authPath . '`', $exception->getMessage(), 'The bad value is named');
        }

        $this->assertSame([], $this->requests, 'Nothing is sent to the vault');
    }

    /**
     * A KV v2 read GETs /v1/secret/data/<path> with the token and returns the inner data map
     *
     * @return void
     */
    public function testReadKvV2SecretSendsTokenAndReturnsInnerData(): void
    {
        $secret = $this->service([
            $this->json(['data' => ['data' => ['username' => 'app', 'password' => 's3cret'], 'metadata' => ['version' => 3]]]),
        ])->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, '/app/db');

        $this->assertSame(['username' => 'app', 'password' => 's3cret'], $secret, 'Only the inner data map is returned, not metadata');

        $request = $this->onlyRequest();
        $this->assertSame('GET', $request->getMethod());
        // A leading slash on the path is trimmed so the URL has no `//`.
        $this->assertSame(self::VAULT_ADDRESS . '/v1/secret/data/app/db', (string) $request->getUri());
        $this->assertSame(self::TOKEN, $request->getHeaderLine('X-Vault-Token'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('', (string) $request->getBody(), 'A read sends no body');
    }

    /**
     * Integer values are stringified; nested, boolean, float and null values are dropped
     *
     * @return void
     */
    public function testReadKvV2SecretKeepsOnlyStringAndIntValues(): void
    {
        $secret = $this->service([
            $this->json(['data' => ['data' => [
                'enabled' => true,
                'host'    => 'db.internal',
                'nested'  => ['a' => 'b'],
                'nothing' => null,
                'port'    => 3306,
                'ratio'   => 1.5,
            ],
            ],
            ]),
        ])->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, 'app/db');

        $this->assertSame(['host' => 'db.internal', 'port' => '3306'], $secret);
    }

    /**
     * A KV response without a data.data map is refused
     *
     * @param array<string, mixed> $body The decoded KV response
     *
     * @return void
     */
    #[DataProvider('kvResponsesWithoutData')]
    public function testReadKvV2SecretThrowsWithoutDataMap(array $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KV read for `app/db` returned no data map');

        $this->service([$this->json($body)])->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, 'app/db');
    }

    /**
     * Dynamic DB creds GET /v1/database/creds/<role> with the role URL-encoded
     *
     * @return void
     */
    public function testReadDatabaseCredentialsReturnsGeneratedPair(): void
    {
        $creds = $this->service([
            $this->json(['lease_id' => 'database/creds/app/abc', 'data' => ['username' => 'v-app-xyz', 'password' => 'gen-pass']]),
        ])->readDatabaseCredentials(self::VAULT_ADDRESS, self::TOKEN, 'app role/x');

        $this->assertSame(['username' => 'v-app-xyz', 'password' => 'gen-pass'], $creds);

        $request = $this->onlyRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::VAULT_ADDRESS . '/v1/database/creds/app%20role%2Fx', (string) $request->getUri());
        $this->assertSame(self::TOKEN, $request->getHeaderLine('X-Vault-Token'));
    }

    /**
     * A creds response without a username/password pair is refused
     *
     * @param array<string, mixed> $body The decoded creds response
     *
     * @return void
     */
    #[DataProvider('credsResponsesWithoutPair')]
    public function testReadDatabaseCredentialsThrowsWithoutPair(array $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database creds for role `app` returned no username/password');

        $this->service([$this->json($body)])->readDatabaseCredentials(self::VAULT_ADDRESS, self::TOKEN, 'app');
    }

    /**
     * An HTTP error status is surfaced as a RuntimeException wrapping the Guzzle error
     *
     * @return void
     */
    public function testHttpErrorStatusThrowsRuntimeExceptionWithPrevious(): void
    {
        try {
            $this->service([new Response(403, [], '{"errors":["permission denied"]}')])
                ->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, 'app/db');
            $this->fail('Expected a RuntimeException for a 403');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('request to `/v1/secret/data/app/db` failed', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious(), 'The Guzzle exception is kept as the previous exception');
        }
    }

    /**
     * A 2xx whose body is not a JSON object or array fails as a RuntimeException, and the body
     * (which a truncated KV response could make a secret) appears nowhere in the exception chain
     *
     * @param string $body  The undecodable response body
     * @param string $cause The decode error's class, the only part of it that is kept
     *
     * @return void
     */
    #[DataProvider('undecodableBodies')]
    public function testUndecodable2xxBodyThrowsRuntimeException(string $body, string $cause): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('uBix Vault response was not decodable JSON', ['cause' => $cause, 'method' => 'GET', 'path' => '/v1/secret/data/app/db', 'status' => 200]);

        try {
            $this->service([new Response(200, ['Content-Type' => 'text/html'], $body)], $logger)
                ->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, 'app/db');
            $this->fail('Expected a RuntimeException for an undecodable body');
        } catch (RuntimeException $exception) {
            $this->assertSame('uBix Vault request to `/v1/secret/data/app/db` returned a 200 whose body is not a JSON object or array (' . $cause . ')', $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'The decode error carries the body, so it is not chained');
        }
    }

    /**
     * An empty 2xx body (e.g. revoke-self's 204) is still an empty response, not a decode failure
     *
     * @return void
     */
    public function testEmpty2xxBodyIsAnEmptyResponse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KV read for `app/db` returned no data map');

        $this->service([new Response(200, [], " \n")])->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, 'app/db');
    }

    /**
     * A transport failure is logged without the token and surfaced as a RuntimeException
     *
     * @return void
     */
    public function testTransportFailureIsLoggedWithoutSecretsAndThrows(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'uBix Vault request failed',
                $this->callback(function (array $context): bool {
                    $this->assertSame(['method' => 'GET', 'path' => '/v1/database/creds/app'], $context);

                    return true;
                }),
            );

        $connectError = new ConnectException('Connection refused', $this->createStub(Request::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->service([$connectError], $logger)->readDatabaseCredentials(self::VAULT_ADDRESS, self::TOKEN, 'app');
    }

    /**
     * Every request is bounded by a timeout and opts into HTTP-error exceptions
     *
     * @return void
     */
    public function testRequestsAreBoundedByTimeoutAndFailOnHttpErrors(): void
    {
        $this->service([$this->json(['data' => ['data' => ['k' => 'v']]])])
            ->readKvV2Secret(self::VAULT_ADDRESS, self::TOKEN, 'app/db');

        $options = $this->handler?->getLastOptions() ?? [];
        $this->assertSame(5, $options['timeout'] ?? null, 'A hung Vault must not hang process startup');
        $this->assertTrue($options['http_errors'] ?? null);
    }

    /**
     * Login responses that carry no usable client token
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function loginResponsesWithoutToken(): array
    {
        return [
            'auth is not a map'  => [['auth' => 'nope']],
            'empty client_token' => [['auth' => ['client_token' => '']]],
            'no auth block'      => [['errors' => ['permission denied']]],
            'no client_token'    => [['auth' => ['policies' => ['default']]]],
            'non-string token'   => [['auth' => ['client_token' => 12345]]],
        ];
    }

    /**
     * Auth mounts that are not one safe path segment
     *
     * @return array<string, array{0: string}>
     */
    public static function unsafeAuthPaths(): array
    {
        return [
            'dot'              => ['.'],
            'dot dot'          => ['..'],
            'dotted name'      => ['jwt.prod'],
            'empty'            => [''],
            'encoded slash'    => ['jwt%2Fx'],
            'leading slash'    => ['/jwt'],
            'nested'           => ['jwt/x'],
            'query'            => ['jwt?x=1'],
            'space'            => ['my jwt'],
            'trailing newline' => ["jwt\n"],
            'traversal'        => ['../../sys'],
        ];
    }

    /**
     * 2xx bodies that do not decode to a JSON object or array
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function undecodableBodies(): array
    {
        return [
            'html error page' => ['<html><body>502 Bad Gateway</body></html>', 'DtoException'],
            'json number'     => ['1', 'TypeError'],
            'json string'     => ['"ok"', 'TypeError'],
            'truncated json'  => ['{"data":{"data":{"password":"s3cret"', 'DtoException'],
        ];
    }

    /**
     * KV responses that carry no inner data map
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function kvResponsesWithoutData(): array
    {
        return [
            'data is not a map'     => [['data' => 'nope']],
            'inner data is not map' => [['data' => ['data' => 'nope']]],
            // A KV v1-shaped response: the secret sits at `data`, not `data.data`.
            'kv v1 shape'           => [['data' => ['username' => 'app']]],
            'no data block'         => [['warnings' => ['deleted']]],
        ];
    }

    /**
     * Creds responses that carry no usable username/password pair
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function credsResponsesWithoutPair(): array
    {
        return [
            'empty username'   => [['data' => ['username' => '', 'password' => 'p']]],
            'missing password' => [['data' => ['username' => 'v-app']]],
            'missing username' => [['data' => ['password' => 'p']]],
            'no data block'    => [['errors' => []]],
            'non-string pair'  => [['data' => ['username' => 1, 'password' => 2]]],
        ];
    }

    /**
     * Build the service over a client that replays the given queue and records requests
     *
     * @param array<int, Response|ConnectException> $queue  Responses (or transport errors), in request order
     * @param ?Logger                               $logger Logger to inject, or a stub when null
     *
     * @return VaultService
     */
    private function service(array $queue, ?Logger $logger = null): VaultService
    {
        $logger ??= $this->createStub(Logger::class);

        $this->requests = [];
        $this->handler  = new MockHandler($queue);
        $stack          = HandlerStack::create($this->handler);
        $stack->push(Middleware::mapRequest(function (Request $request): Request {
            $this->requests[] = $request;

            return $request;
        }));

        return new VaultService($logger, new Client(['handler' => $stack]), new JsonService($logger));
    }

    /**
     * A 200 JSON response carrying the given body
     *
     * @param array<string, mixed> $body The response body before encoding
     *
     * @return Response
     */
    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (new JsonService($this->createStub(Logger::class)))->encode($body));
    }

    /**
     * The single request the client sent, failing the test if it sent any other number
     *
     * @return Request
     */
    private function onlyRequest(): Request
    {
        $this->assertCount(1, $this->requests, 'Exactly one request is sent');

        return $this->requests[0];
    }

    /**
     * Decode a recorded request's JSON body
     *
     * @param Request $request The recorded request
     *
     * @return array<int|string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        return (new JsonService($this->createStub(Logger::class)))->decode((string) $request->getBody());
    }
}
