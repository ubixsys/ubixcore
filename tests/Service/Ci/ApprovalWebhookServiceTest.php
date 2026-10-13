<?php

declare(strict_types=1);

namespace Ubix\Tests\Service\Ci;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Service\Ci\ApprovalWebhookService;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Service\Ci\ApprovalWebhookService
 *
 * What must hold: only a correct secret gets in; only an approve or un-approve does
 * anything; only the newest sign-off job is retried, never a new pipeline; and nothing
 * but a wrong secret is ever answered with an error status.
 *
 * @coversDefaultClass \Ubix\Service\Ci\ApprovalWebhookService
 */
final class ApprovalWebhookServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const SECRET = 's3cret';

    /**
     * Reply bodies by "METHOD path"
     *
     * @var array<string, string>
     */
    private array $replies = [];

    /**
     * Requests sent, as "METHOD path"
     *
     * @var list<string>
     */
    private array $sent = [];

    /**
     * Tokens the requests carried, in order
     *
     * @var list<string>
     */
    private array $tokensUsed = [];

    /**
     * Whether the stubbed client fails at the transport
     */
    private bool $unreachable = false;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ApprovalWebhookService::class);
    }

    /**
     * A wrong or missing secret is refused, and nothing is sent to GitLab
     *
     * @return void
     */
    public function testAWrongSecretIsRefused(): void
    {
        $this->assertSame(401, $this->buildService()->handle('nope', $this->event('approved'))['status']);
        $this->assertSame(401, $this->buildService(secret: '')->handle('', $this->event('approved'))['status']);
        $this->assertSame([], $this->sent);
    }

    /**
     * Anything but an approve or un-approve is acknowledged and ignored
     *
     * @return void
     */
    public function testOtherEventsAreIgnored(): void
    {
        $result = $this->buildService()->handle(self::SECRET, $this->event('update'));

        $this->assertSame(200, $result['status']);
        $this->assertStringStartsWith('ignored', $result['outcome']);
        $this->assertSame([], $this->sent);
    }

    /**
     * An approval retries the newest sign-off job on the head pipeline, and nothing else
     *
     * @return void
     */
    public function testAnApprovalRetriesTheNewestSignOffJob(): void
    {
        $this->givenPipeline([
            ['id' => 10, 'name' => 'require-approval-mr', 'status' => 'failed'],
            ['id' => 12, 'name' => 'require-approval-mr', 'status' => 'failed'],
            ['id' => 11, 'name' => 'phpunit-mr', 'status' => 'failed'],
        ]);

        $result = $this->buildService()->handle(self::SECRET, $this->event('approved'));

        $this->assertSame(200, $result['status']);
        $this->assertStringStartsWith('retried: job 12', $result['outcome']);
        $this->assertContains('POST /api/v4/projects/7/jobs/12/retry', $this->sent);
        $this->assertNotContains('POST /api/v4/projects/7/jobs/11/retry', $this->sent);
        // One retry, of one job, and never a new pipeline (which would re-run every check).
        $this->assertSame(['POST /api/v4/projects/7/jobs/12/retry'], array_values(array_filter($this->sent, static function (string $call): bool {
            return str_starts_with($call, 'POST ');
        })));
    }

    /**
     * Un-approving re-runs it too, so the verdict can turn red again
     *
     * @return void
     */
    public function testAnUnapprovalAlsoRetries(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'success']]);

        $this->assertStringStartsWith('retried', $this->buildService()->handle(self::SECRET, $this->event('unapproved'))['outcome']);
    }

    /**
     * A job already queued or running is left alone rather than duplicated
     *
     * @return void
     */
    public function testAQueuedJobIsNotRetriedAgain(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval-mr', 'status' => 'pending']]);

        $this->assertStringStartsWith('skipped', $this->buildService()->handle(self::SECRET, $this->event('approved'))['outcome']);
        $this->assertNotContains('POST /api/v4/projects/7/jobs/12/retry', $this->sent);
    }

    /**
     * A project with no token is ignored, not an error
     *
     * @return void
     */
    public function testAProjectWithoutATokenIsIgnored(): void
    {
        $result = $this->buildService(tokens: '')->handle(self::SECRET, $this->event('approved'));

        $this->assertSame(200, $result['status']);
        $this->assertStringStartsWith('ignored', $result['outcome']);
    }

    /**
     * A project with no pair of its own uses the fallback token
     *
     * @return void
     */
    public function testTheFallbackTokenServesAnyProject(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'failed']]);

        $result = $this->buildService(tokens: '', apiToken: 'group-tok')->handle(self::SECRET, $this->event('approved'));

        $this->assertStringStartsWith('retried', $result['outcome']);
        $this->assertSame(['group-tok'], array_values(array_unique($this->tokensUsed)));
    }

    /**
     * A project's own token wins over the fallback
     *
     * @return void
     */
    public function testAProjectTokenWinsOverTheFallback(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'failed']]);

        $this->buildService(apiToken: 'group-tok')->handle(self::SECRET, $this->event('approved'));

        $this->assertSame(['tok'], array_values(array_unique($this->tokensUsed)));
    }

    /**
     * A manual job that has not run is played, not retried
     *
     * @return void
     */
    public function testAManualJobIsPlayed(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'manual']]);
        $this->replies['POST /api/v4/projects/7/jobs/12/play'] = '{}';

        $result = $this->buildService()->handle(self::SECRET, $this->event('approved'));

        $this->assertStringStartsWith('played: job 12', $result['outcome']);
        $this->assertContains('POST /api/v4/projects/7/jobs/12/play', $this->sent);
        $this->assertNotContains('POST /api/v4/projects/7/jobs/12/retry', $this->sent);
    }

    /**
     * An approval of a job that already passed changes nothing, so it is not re-run
     *
     * @return void
     */
    public function testAPassedJobIsNotRetriedOnApproval(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'success']]);

        $result = $this->buildService()->handle(self::SECRET, $this->event('approved'));

        $this->assertStringStartsWith('skipped', $result['outcome']);
        $this->assertNotContains('POST /api/v4/projects/7/jobs/12/retry', $this->sent);
    }

    /**
     * A thumbs-up awarded to a merge request re-runs the job
     *
     * @return void
     */
    public function testAThumbsUpRetriesTheJob(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'failed']]);

        $result = $this->buildService()->handle(self::SECRET, $this->emojiEvent('thumbsup', 'award', 'MergeRequest'));

        $this->assertStringStartsWith('retried: job 12', $result['outcome']);
    }

    /**
     * The verb in `object_attributes.action`, the shape GitLab 19.4.2 does not send, still counts
     *
     * @return void
     */
    public function testALegacyShapedThumbsUpRetriesTheJob(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'failed']]);

        $result = $this->buildService()->handle(self::SECRET, $this->emojiEvent('thumbsup', 'award', 'MergeRequest', true));

        $this->assertStringStartsWith('retried: job 12', $result['outcome']);
    }

    /**
     * Revoking a thumbs-up re-runs even a passed job, so the verdict can turn red
     *
     * @return void
     */
    public function testRevokingAThumbsUpRetriesAPassedJob(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'success']]);

        $this->assertStringStartsWith('retried', $this->buildService()->handle(self::SECRET, $this->emojiEvent('thumbsup', 'revoke', 'MergeRequest'))['outcome']);
    }

    /**
     * Other emoji, and emoji on anything but a merge request, are ignored
     *
     * @return void
     */
    public function testOtherEmojiAreIgnored(): void
    {
        $service = $this->buildService();

        $this->assertStringStartsWith('ignored', $service->handle(self::SECRET, $this->emojiEvent('heart', 'award', 'MergeRequest'))['outcome']);
        $this->assertStringStartsWith('ignored', $service->handle(self::SECRET, $this->emojiEvent('thumbsup', 'award', 'Issue'))['outcome']);
        $this->assertSame([], $this->sent);
    }

    /**
     * An unreachable GitLab is still answered 200, so GitLab keeps the webhook enabled
     *
     * @return void
     */
    public function testAnOutageIsAnsweredOk(): void
    {
        $this->unreachable = true;

        $result = $this->buildService()->handle(self::SECRET, $this->event('approved'));

        $this->assertSame(200, $result['status']);
        $this->assertStringStartsWith('error', $result['outcome']);
    }

    /**
     * The token list reads as the environment carries it; a malformed pair is skipped
     *
     * @return void
     */
    public function testProjectTokensParse(): void
    {
        $this->givenPipeline([['id' => 12, 'name' => 'require-approval', 'status' => 'failed']]);

        $service = $this->buildService(tokens: ' broken, =x, 7=tok=with=equals ');

        $this->assertStringStartsWith('retried', $service->handle(self::SECRET, $this->event('approved'))['outcome']);
    }

    /**
     * Record a request and answer it from the canned replies
     *
     * @param Request $request The request
     *
     * @throws HttpClientException When the client is set to be unreachable
     *
     * @return Psr7Response The reply
     */
    public function answer(Request $request): Psr7Response
    {
        if ($this->unreachable) {
            throw new HttpClientException('There was a cURL error (6: could not resolve host).');
        }

        $key                = $request->getMethod() . ' ' . $request->getUri()->getPath();
        $this->sent[]       = $key;
        $this->tokensUsed[] = $request->getHeaderLine('PRIVATE-TOKEN');

        return array_key_exists($key, $this->replies) ? new Psr7Response(200, [], $this->replies[$key]) : new Psr7Response(404, [], '{}');
    }

    /**
     * MR !3 of project 7 with head pipeline 55 holding these jobs
     *
     * @param array<array{id: int, name: string, status: string}> $jobs The jobs
     *
     * @return void
     */
    private function givenPipeline(array $jobs): void
    {
        $json = new JsonService($this->createStub(Logger::class));

        $this->replies['GET /api/v4/projects/7/merge_requests/3']  = $json->encode(['head_pipeline' => ['id' => 55]]);
        $this->replies['GET /api/v4/projects/7/pipelines/55/jobs'] = $json->encode($jobs);
        $this->replies['POST /api/v4/projects/7/jobs/12/retry']    = $json->encode(['id' => 99]);
    }

    /**
     * A merge-request event body
     *
     * @param string $action The MR action
     *
     * @return string The JSON payload
     */
    private function event(string $action): string
    {
        return (new JsonService($this->createStub(Logger::class)))->encode([
            'object_attributes' => ['action' => $action, 'iid' => 3],
            'object_kind'       => 'merge_request',
            'project'           => ['id' => 7],
        ]);
    }

    /**
     * An emoji event body for MR !3 of project 7
     *
     * @param string $name          The emoji
     * @param string $action        Either `award` or `revoke`
     * @param string $awardableType What it was awarded to
     * @param bool   $legacy        Put the verb in `object_attributes.action` instead of the top-level `event_type`
     *
     * @return string The JSON payload
     */
    private function emojiEvent(string $name, string $action, string $awardableType, bool $legacy = false): string
    {
        $attributes = ['awardable_type' => $awardableType, 'name' => $name];
        $event      = [
            'merge_request'     => ['iid' => 3],
            'object_attributes' => $legacy ? $attributes + ['action' => $action] : $attributes,
            'object_kind'       => 'emoji',
            'project'           => ['id' => 7],
        ];

        if (!$legacy) {
            $event['event_type'] = $action;
        }

        return (new JsonService($this->createStub(Logger::class)))->encode($event);
    }

    /**
     * The service under test
     *
     * @param string $secret   The webhook secret it expects
     * @param string $tokens   Project tokens, `id=token,...`
     * @param string $apiToken Fallback token
     *
     * @return ApprovalWebhookService The service
     */
    private function buildService(string $secret = self::SECRET, string $tokens = '7=tok', string $apiToken = ''): ApprovalWebhookService
    {
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturnCallback($this->answer(...));
        $logger = $this->createStub(Logger::class);

        return new ApprovalWebhookService($logger, $httpClient, new Psr17Factory(), new JsonService($logger), 'https://gitlab.test/api/v4', $secret, $tokens, $apiToken, ['require-approval', 'require-approval-mr']);
    }
}
