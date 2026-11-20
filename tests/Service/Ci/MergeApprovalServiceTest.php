<?php

declare(strict_types=1);

namespace Ubix\Tests\Service\Ci;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Exception\HttpClientException;
use Ubix\Service\Ci\MergeApprovalService;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Service\Ci\MergeApprovalService
 *
 * The rule under test: a sign-off from anyone but the author counts; the author's own
 * counts only when the author is a named owner.
 *
 * @coversDefaultClass \Ubix\Service\Ci\MergeApprovalService
 */
final class MergeApprovalServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const API = 'https://gitlab.test/api/v4';

    /**
     * Reply bodies by request path (no query string)
     *
     * @var array<string, string>
     */
    private array $replies = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MergeApprovalService::class);
    }

    /**
     * Nobody has signed off: not approved
     *
     * @return void
     */
    public function testNoSignOffIsNotApproved(): void
    {
        $this->givenMergeRequest('dev', [], []);

        $this->assertFalse($this->buildService()->getVerdict(self::API, '7', 't', 3, [])['approved']);
    }

    /**
     * Only a listed owner's Approve counts; anyone else's is reported as ignored
     *
     * @return void
     */
    public function testOnlyAnOwnersApprovalCounts(): void
    {
        $this->givenMergeRequest('dev', ['olga'], []);

        $verdict = $this->buildService()->getVerdict(self::API, '7', 't', 3, ['cwolsen']);
        $this->assertFalse($verdict['approved']);
        $this->assertSame([], $verdict['signedOffBy']);
        $this->assertSame(['olga'], $verdict['ignored']);

        $this->givenMergeRequest('dev', ['olga', 'cwolsen'], []);

        $verdict = $this->buildService()->getVerdict(self::API, '7', 't', 3, ['cwolsen']);
        $this->assertTrue($verdict['approved']);
        $this->assertSame(['cwolsen'], $verdict['signedOffBy']);
        $this->assertFalse($verdict['selfSignOff']);
    }

    /**
     * A bot or agent token's Approve or thumbs-up never satisfies the gate
     *
     * @return void
     */
    public function testBotsAndAgentsCannotSignOff(): void
    {
        $this->givenMergeRequest('cwolsen', ['group_223_bot_x'], [['agent-bot', 'thumbsup']]);

        $verdict = $this->buildService()->getVerdict(self::API, '7', 't', 3, ['cwolsen']);
        $this->assertFalse($verdict['approved']);
        $this->assertSame(['group_223_bot_x', 'agent-bot'], $verdict['ignored']);
    }

    /**
     * A thumbs-up counts the same as Approve; other emoji do not
     *
     * @return void
     */
    public function testAThumbsUpCountsAndOtherEmojiDoNot(): void
    {
        $this->givenMergeRequest('dev', [], [['cwolsen', 'thumbsup']]);
        $this->assertTrue($this->buildService()->getVerdict(self::API, '7', 't', 3, ['cwolsen'])['approved']);

        $this->givenMergeRequest('dev', [], [['cwolsen', 'heart']]);
        $this->assertFalse($this->buildService()->getVerdict(self::API, '7', 't', 3, ['cwolsen'])['approved']);
    }

    /**
     * An author's own sign-off counts only when the author is an owner, and then it says so
     *
     * @return void
     */
    public function testSelfSignOffCountsOnlyForOwners(): void
    {
        $this->givenMergeRequest('cwolsen', ['cwolsen'], []);

        $this->assertFalse($this->buildService()->getVerdict(self::API, '7', 't', 3, [])['approved']);

        $verdict = $this->buildService()->getVerdict(self::API, '7', 't', 3, ['cwolsen']);
        $this->assertTrue($verdict['approved']);
        $this->assertTrue($verdict['selfSignOff']);
    }

    /**
     * A branch pipeline finds its MR by source branch
     *
     * @return void
     */
    public function testTheOpenMergeRequestIsFoundByBranch(): void
    {
        $this->replies['/api/v4/projects/7/merge_requests'] = '[{"iid":42}]';

        $this->assertSame(42, $this->buildService()->getOpenMergeRequestIid(self::API, '7', 't', 'feat/x'));

        $this->replies['/api/v4/projects/7/merge_requests'] = '[]';
        $this->assertNull($this->buildService()->getOpenMergeRequestIid(self::API, '7', 't', 'feat/x'));
    }

    /**
     * An API failure is an exception, which the command turns into fail-open
     *
     * @return void
     */
    public function testAnApiFailureThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->buildService()->getVerdict(self::API, '7', 't', 99, []);
    }

    /**
     * A transport failure (timeout, DNS, TLS) arrives as a RuntimeException
     *
     * The command handles RuntimeException; a raw client exception escaped it and
     * killed the job with exit 255 (kitg pipeline 6431, a 5 s timeout).
     *
     * @return void
     */
    public function testATransportFailureBecomesARuntimeException(): void
    {
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willThrowException(new HttpClientException('There was a cURL error (28: timed out).'));
        $logger  = $this->createStub(Logger::class);
        $service = new MergeApprovalService($logger, $httpClient, new Psr17Factory(), new JsonService($logger));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        $service->getVerdict(self::API, '7', 't', 3, []);
    }

    /**
     * Hand back the canned reply for a request's path, or a 404
     *
     * @param Request $request The request
     *
     * @return Psr7Response The reply
     */
    public function answer(Request $request): Psr7Response
    {
        $path = $request->getUri()->getPath();

        return array_key_exists($path, $this->replies) ? new Psr7Response(200, [], $this->replies[$path]) : new Psr7Response(404, [], '{}');
    }

    /**
     * Set up MR !3 of project 7
     *
     * @param string                       $author    Author username
     * @param string[]                     $approvers Who pressed Approve
     * @param array<array{string, string}> $awards    Pairs of [username, emoji name]
     *
     * @return void
     */
    private function givenMergeRequest(string $author, array $approvers, array $awards): void
    {
        $base = '/api/v4/projects/7/merge_requests/3';
        $json = new JsonService($this->createStub(Logger::class));

        $this->replies[$base] = $json->encode(['author' => ['username' => $author]]);
        $approvedBy           = array_map(static function (string $name): array {
            return ['user' => ['username' => $name]];
        }, $approvers);

        $this->replies[$base . '/approvals']   = $json->encode(['approved_by' => $approvedBy]);
        $this->replies[$base . '/award_emoji'] = $json->encode(array_map(static function (array $award): array {
            return ['name' => $award[1], 'user' => ['username' => $award[0]]];
        }, $awards));
    }

    /**
     * The service under test, over a stubbed HTTP client
     *
     * @return MergeApprovalService The service
     */
    private function buildService(): MergeApprovalService
    {
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturnCallback($this->answer(...));
        $logger = $this->createStub(Logger::class);

        return new MergeApprovalService($logger, $httpClient, new Psr17Factory(), new JsonService($logger));
    }
}
