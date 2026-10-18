<?php

declare(strict_types=1);

namespace Ubix\Middleware;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface as ResponseFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Rejects (403) requests whose session lacks a required role
 *
 * Attach per route group with the required role; roles prove *who* — ownership
 * checks stay in the owning surface's service (authentication SRS FR-41/42).
 *
 * @see \Ubix\Tests\Middleware\RoleAuthorizationMiddlewareTest PHPUnit test case
 */
final class RoleAuthorizationMiddleware implements Middleware
{
    /**
     * Constructor
     *
     * @param Logger          $logger          The Monolog logger
     * @param ResponseFactory $responseFactory Response factory for creating responses
     * @param JsonService     $jsonService     JSON encoder for error bodies
     * @param string          $requiredRole    The role the session must hold (no default; the host names its own)
     *
     * @throws InvalidArgumentException When the role is empty or whitespace
     */
    public function __construct(
        private Logger $logger,
        private ResponseFactory $responseFactory,
        private JsonService $jsonService,
        private string $requiredRole,
    ) {
        if (trim($requiredRole) === '') {
            throw new InvalidArgumentException('The required role must not be empty');
        }
    }

    /**
     * Reject the request unless the session user holds the required role
     *
     * @param Request $request PSR request
     * @param Handler $handler PSR handler
     *
     * @return Response PSR response
     */
    public function process(Request $request, Handler $handler): Response
    {
        $sessionUser = $_SESSION['user'] ?? null;
        $roles       = is_array($sessionUser) && is_string($sessionUser['roles'] ?? null) ? $sessionUser['roles'] : '';

        if (!in_array($this->requiredRole, array_map('trim', explode(',', $roles)), true)) {
            $this->logger->info('Role authorization rejected', [
                'path'          => $request->getUri()->getPath(),
                'required_role' => $this->requiredRole,
                'user_id'       => is_array($sessionUser) ? ($sessionUser['id'] ?? null) : null,
            ]);
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write($this->jsonService->encode(['message' => 'Forbidden']));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
