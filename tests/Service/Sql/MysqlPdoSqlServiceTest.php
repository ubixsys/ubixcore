<?php

declare(strict_types=1);

namespace Ubix\Tests\Service\Sql;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Ubix\Exception\DtoException;
use Ubix\Service\Sql\AbstractPdoSqlService as PdoSqlService;
use Ubix\Service\Sql\MysqlPdoSqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Ubix\Service\Sql\MysqlPdoSqlService
 *
 * @coversDefaultClass \Ubix\Service\Sql\MysqlPdoSqlService
 */
final class MysqlPdoSqlServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Unset or blank MYSQL_TIME_ZONE means no time zone is applied
     *
     * @return void
     */
    public function testUnsetTimeZoneIsNull(): void
    {
        putenv('MYSQL_TIME_ZONE');
        $this->assertNull($this->timeZone());

        putenv('MYSQL_TIME_ZONE=  ');
        $this->assertNull($this->timeZone());
    }

    /**
     * SYSTEM, offsets and named zones are accepted as written
     *
     * @param string $value The MYSQL_TIME_ZONE value
     *
     * @return void
     */
    #[DataProvider('validTimeZones')]
    public function testValidTimeZonesAreAccepted(string $value): void
    {
        putenv('MYSQL_TIME_ZONE=' . $value);
        $this->assertSame($value, $this->timeZone());
    }

    /**
     * Anything else is refused, naming MYSQL_TIME_ZONE
     *
     * @param string $value The MYSQL_TIME_ZONE value
     *
     * @return void
     */
    #[DataProvider('invalidTimeZones')]
    public function testInvalidTimeZonesAreRefused(string $value): void
    {
        putenv('MYSQL_TIME_ZONE=' . $value);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MYSQL_TIME_ZONE/');
        $this->timeZone();
    }

    /**
     * With no time zone set, no SET is issued
     *
     * @return void
     */
    public function testNoSetIsIssuedWhenUnset(): void
    {
        putenv('MYSQL_TIME_ZONE');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->apply($pdo);
    }

    /**
     * A set value is bound (not interpolated) into SET time_zone on every connection it is applied to, read and write alike
     *
     * @return void
     */
    public function testTimeZoneIsBoundOnEveryConnection(): void
    {
        putenv('MYSQL_TIME_ZONE=+00:00');

        for ($connection = 0; $connection < 2; $connection++) { // Once for the read connection, once for the write connection
            $statement = $this->createMock(PDOStatement::class);
            $statement->expects($this->once())->method('execute')->with(['+00:00'])->willReturn(true);
            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())->method('prepare')->with('SET time_zone = ?')->willReturn($statement);

            $this->apply($pdo);
        }
    }

    /**
     * A refused SET fails loudly, naming MYSQL_TIME_ZONE and the tz-tables hint
     *
     * @return void
     */
    public function testRefusedTimeZoneFailsLoudly(): void
    {
        putenv('MYSQL_TIME_ZONE=America/New_York');
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException("Unknown or incorrect time zone: 'America/New_York'"));

        try {
            $this->apply($pdo);
            $this->fail('Expected a DtoException');
        } catch (DtoException $e) {
            $this->assertStringContainsString('MYSQL_TIME_ZONE', $e->getMessage());
            $this->assertStringContainsString('time-zone tables', $e->getMessage());
        }
    }

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MysqlPdoSqlService::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTimeZones(): array
    {
        return [
            'etc zone'        => ['Etc/GMT+5'],
            'max east'        => ['+14:00'],
            'max west'        => ['-13:59'],
            'named'           => ['America/New_York'],
            'negative offset' => ['-05:30'],
            'short name'      => ['UTC'],
            'system'          => ['SYSTEM'],
            'utc offset'      => ['+00:00'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTimeZones(): array
    {
        return [
            'bad minutes'   => ['+05:60'],
            'injection'     => ["UTC'; DROP TABLE users; --"],
            'leading digit' => ['1UTC'],
            'negative zero' => ['-00:00'],
            'no sign'       => ['05:00'],
            'quote'         => ["+00:00'"],
            'space'         => ['abc def'],
            'too far east'  => ['+14:01'],
            'too far west'  => ['-14:00'],
            'too long'      => [str_repeat('a', 65)],
        ];
    }

    /**
     * Clear the time zone setting
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('MYSQL_TIME_ZONE');
        parent::tearDown();
    }

    /**
     * Read MYSQL_TIME_ZONE through the protected connectionTimeZone() hook
     *
     * @return ?string
     */
    private function timeZone(): ?string
    {
        $result = $this->callProtected('connectionTimeZone');
        $this->assertTrue($result === null || is_string($result));

        return $result;
    }

    /**
     * Apply MYSQL_TIME_ZONE to a connection through the protected applyConnectionTimeZone() hook
     *
     * @param PDO $pdo A connection
     *
     * @return void
     */
    private function apply(PDO $pdo): void
    {
        $this->callProtected('applyConnectionTimeZone', $pdo);
    }

    /**
     * Call a protected method of the abstract service on a bare concrete subclass
     *
     * @param string $method  Method name
     * @param mixed  ...$args Arguments
     *
     * @return mixed
     */
    private function callProtected(string $method, mixed ...$args): mixed
    {
        $service = new class (new NullLogger()) extends PdoSqlService {
        };

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }
}
