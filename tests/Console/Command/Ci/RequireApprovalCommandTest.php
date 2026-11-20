<?php

declare(strict_types=1);

namespace Ubix\Tests\Console\Command\Ci;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Console\Command\Ci\RequireApprovalCommand;
use Ubix\Service\Ci\MergeApprovalService;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Console\Command\Ci\RequireApprovalCommand
 *
 * @coversDefaultClass \Ubix\Console\Command\Ci\RequireApprovalCommand
 */
final class RequireApprovalCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(RequireApprovalCommand::class);
    }

    /**
     * With no token the job passes, loudly, rather than holding every merge
     *
     * @return void
     */
    public function testWithoutATokenItFailsOpen(): void
    {
        putenv('APPROVAL_GITLAB_TOKEN');
        putenv('AI_REVIEW_GITLAB_TOKEN');

        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->never())->method('sendRequest');
        $logger = $this->createStub(Logger::class);

        $tester = new CommandTester(new RequireApprovalCommand($logger, new MergeApprovalService($logger, $httpClient, new Psr17Factory(), new JsonService($logger))));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('UNVERIFIED', $tester->getDisplay());
    }

    /**
     * With a token but no owners listed it fails before asking GitLab: nobody's sign-off could count
     *
     * @return void
     */
    public function testWithoutOwnersItFails(): void
    {
        putenv('APPROVAL_GITLAB_TOKEN=t');
        putenv('MERGE_APPROVAL_OWNERS');

        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->never())->method('sendRequest');
        $logger = $this->createStub(Logger::class);

        $tester = new CommandTester(new RequireApprovalCommand($logger, new MergeApprovalService($logger, $httpClient, new Psr17Factory(), new JsonService($logger))));
        $code   = $tester->execute([]);
        putenv('APPROVAL_GITLAB_TOKEN');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('set MERGE_APPROVAL_OWNERS', $tester->getDisplay());
    }
}
