<?php

declare(strict_types=1);

namespace Ubix\Tests\Service;

use Psr\Log\NullLogger;
use ReflectionMethod;
use Ubix\Service\JsonService;
use Ubix\Service\MachineCodeReviewService;
use Ubix\Service\NativeProcessService;
use Ubix\Service\ProjectRootService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Service\MachineCodeReviewService
 *
 * @coversDefaultClass \Ubix\Service\MachineCodeReviewService
 */
final class MachineCodeReviewServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MachineCodeReviewService::class);
    }

    /**
     * Test that an unreadable PHPStan result cache falls back to the default paths instead of crashing
     *
     * @return void
     */
    public function testPhpstanFilesAnalyzedSurvivesUnreadableCache(): void
    {
        $root = sys_get_temp_dir() . '/ubix-mcrs-' . bin2hex(random_bytes(4));
        mkdir($root . '/php', 0777, true);
        mkdir($root . '/.phpstan.cache', 0777, true);
        file_put_contents($root . '/php/A.php', '<?php');
        file_put_contents($root . '/.phpstan.cache/resultCache.php', '<?php // no return, so require yields int 1');

        try {
            $logger  = new NullLogger();
            $service = new MachineCodeReviewService(
                $logger,
                new JsonService($logger),
                new NativeProcessService($logger),
                new ProjectRootService($logger, $root),
            );
            $method  = new ReflectionMethod($service, 'getPhpstanFilesAnalyzed');

            $this->assertSame([(string) realpath($root) . '/php/A.php'], $method->invoke($service, []));
            $this->assertSame(['x.php'], $method->invoke($service, ['x.php']));
        } finally {
            unlink($root . '/.phpstan.cache/resultCache.php');
            unlink($root . '/php/A.php');
            rmdir($root . '/.phpstan.cache');
            rmdir($root . '/php');
            rmdir($root);
        }
    }
}
