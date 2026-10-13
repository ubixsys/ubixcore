<?php

declare(strict_types=1);

namespace Ubix\Tests\Controller\Ops;

use Latte\Engine;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Controller\Ops\OpsController;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\Ci\ApprovalWebhookService;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Controller\Ops\OpsController
 *
 * @coversDefaultClass \Ubix\Controller\Ops\OpsController
 */
final class OpsControllerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const SECRET = 's3cret';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(OpsController::class);
    }

    /**
     * An emoji delivery with the wrong secret is the one thing answered with an error
     *
     * @covers ::gitlabMergeRequestHook
     *
     * @return void
     */
    public function testAnEmojiDeliveryWithAWrongSecretIs401(): void
    {
        $response = $this->deliver('wrong', $this->emojiBody('thumbsup'));

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * An emoji delivery with the right secret is answered 200, even when it is ignored
     *
     * @covers ::gitlabMergeRequestHook
     *
     * @return void
     */
    public function testAnEmojiDeliveryWithTheRightSecretIs200(): void
    {
        $response = $this->deliver(self::SECRET, $this->emojiBody('heart'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('ignored', (string) $response->getBody());
    }

    /**
     * Send one delivery through the controller
     *
     * @param string $secret The `X-Gitlab-Token` header
     * @param string $body   The payload
     *
     * @return Response The answer
     */
    private function deliver(string $secret, string $body): Response
    {
        $logger  = $this->createStub(Logger::class);
        $factory = new Psr17Factory();
        $json    = new JsonService($logger);
        $service = new ApprovalWebhookService($logger, $this->createStub(HttpClient::class), $factory, $json, 'https://gitlab.test/api/v4', self::SECRET, '7=tok', '', ['require-approval']);
        $request = $factory->createServerRequest('POST', '/gitlab/merge-request-hook')
            ->withHeader('X-Gitlab-Event', 'Emoji Hook')
            ->withHeader('X-Gitlab-Token', $secret)
            ->withBody($factory->createStream($body));

        return (new OpsController($logger, new TemplateRenderer($logger, new Engine()), $json, $service))
            ->gitlabMergeRequestHook($request, $factory->createResponse());
    }

    /**
     * An emoji event body for a merge request
     *
     * @param string $name The emoji
     *
     * @return string The JSON payload
     */
    private function emojiBody(string $name): string
    {
        return (new JsonService($this->createStub(Logger::class)))->encode([
            'event_type'        => 'award',
            'merge_request'     => ['iid' => 3],
            'object_attributes' => ['awardable_type' => 'MergeRequest', 'name' => $name],
            'object_kind'       => 'emoji',
            'project'           => ['id' => 7],
        ]);
    }
}
