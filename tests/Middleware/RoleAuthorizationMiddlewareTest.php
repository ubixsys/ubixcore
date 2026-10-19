<?php

declare(strict_types=1);

namespace Ubix\Tests\Middleware;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface as Logger;
use ReflectionParameter;
use Ubix\Middleware\RoleAuthorizationMiddleware;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Middleware\RoleAuthorizationMiddleware
 *
 * @coversDefaultClass \Ubix\Middleware\RoleAuthorizationMiddleware
 */
final class RoleAuthorizationMiddlewareTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(RoleAuthorizationMiddleware::class);
    }

    /**
     * Allow/deny matrix over the multi-value roles field
     *
     * @return void
     */
    public function testAllowDenyMatrix(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/creator/only');
        $handler = $this->createStub(Handler::class);
        $handler->method('handle')->willReturn($factory->createResponse(200));

        $middleware = new RoleAuthorizationMiddleware(
            $this->createStub(Logger::class),
            $factory,
            new JsonService($this->createStub(Logger::class)),
            'creator',
        );

        $cases = [
            ['creator', 200],
            ['member,creator', 200],
            [' creator , admin ', 200],
            ['member', 403],
            ['', 403],
            [null, 403],
        ];

        foreach ($cases as [$roles, $expected]) {
            $_SESSION['user'] = $roles === null ? [] : ['id' => 1, 'roles' => $roles];
            $this->assertSame($expected, $middleware->process($request, $handler)->getStatusCode(), 'roles=' . ($roles ?? 'null'));
        }

        unset($_SESSION['user']);
    }

    /**
     * An empty or whitespace-only role is refused at construction
     *
     * @return void
     */
    public function testEmptyRoleIsRejected(): void
    {
        $factory = new Psr17Factory();

        foreach (['', '   '] as $role) {
            try {
                new RoleAuthorizationMiddleware(
                    $this->createStub(Logger::class),
                    $factory,
                    new JsonService($this->createStub(Logger::class)),
                    $role,
                );
                $this->fail('Expected InvalidArgumentException for role "' . $role . '"');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The required role must not be empty', $exception->getMessage());
            }
        }
    }

    /**
     * The required role has no default, so the framework names no product role
     *
     * @return void
     */
    public function testRequiredRoleHasNoDefault(): void
    {
        $parameter = new ReflectionParameter([RoleAuthorizationMiddleware::class, '__construct'], 'requiredRole');

        $this->assertFalse($parameter->isDefaultValueAvailable());
    }
}
