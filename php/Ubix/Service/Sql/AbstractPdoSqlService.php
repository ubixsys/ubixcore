<?php

declare(strict_types=1);

namespace Ubix\Service\Sql;

use Exception;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface as Logger;
use Ubix\DataTransferObject\PdoConstructorParameters;
use Ubix\DataTransferObject\PdoError;
use Ubix\Enum\Exception\ExceptionCode;
use Ubix\Exception\DtoException;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * Abstract service to access a database using PDO
 */
abstract class AbstractPdoSqlService implements SqlService
{
    private PdoConstructorParameters $readPdoConstructorParameters;

    private PdoConstructorParameters $writePdoConstructorParameters;

    /**
     * An array of PDO objects available as singletons stored with a key that is the MD5 hash of the PDO constructor parameters
     *
     * @var array<string, PDO> $singletons
     */
    private static array $singletons = [];

    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private Logger $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function query(string $sql, array $parameters = [], bool $allowParameterReuse = false): int
    {
        $this->ensureInitialized();

        // TEMPORARY: Ignore TRUNCATE statements during PHPUnit tests just until we are comfortable.
        if (stripos($sql, 'TRUNCATE') !== false && $this->writePdoConstructorParameters->username !== 'root') {
            // During PHPUnit tests we want to ignore TRUNCATE statements as they can cause issues with foreign key constraints
            $this->logger->info('Ignoring TRUNCATE statement during PHPUnit tests.', ['sql' => $sql, 'parameters' => $parameters]);
            return 0;
        }

        return $this->getPdoStatement(true, $sql, $parameters, $allowParameterReuse)->rowCount();
    }

    /**
     * {@inheritDoc}
     */
    public function getColumn(string $sql, array $parameters = [], bool $allowParameterReuse = false): bool|int|float|string|null
    {
        $column = $this->getPdoStatement(false, $sql, $parameters, $allowParameterReuse)->fetchColumn(0);
        assert(is_scalar($column) || $column === null);
        return $column;
    }

    /**
     * {@inheritDoc}
     */
    public function getRow(string $sql, array $parameters = [], bool $allowParameterReuse = false): array|false
    {
        /**
         * @var array<string, bool|int|float|string|null>|false $row
         */
        $row = $this->getPdoStatement(false, $sql, $parameters, $allowParameterReuse)->fetch();
        return $row;
    }

    /**
     * {@inheritDoc}
     *
     * @return Generator<int, array<string, bool|int|float|string|null>, never, void> Yields each row from the result set as an array
     */
    public function getRows(string $sql, array $parameters = [], bool $allowParameterReuse = false): Generator
    {
        $pdoStatement = $this->getPdoStatement(false, $sql, $parameters, $allowParameterReuse);
        while (($row = $pdoStatement->fetch()) !== false) {
            /**
             * @var array<string, bool|int|float|string|null> $row
             */
            yield $row;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->getPdo(true)->lastInsertId($name);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception If the transaction fails to begin
     */
    public function beginTransaction(): void
    {
        if ($this->getPdo(true)->beginTransaction() === false) {
            throw new Exception('The transaction failed to begin.', ExceptionCode::BEGIN_TRANSACTION_FAILED_IN_PDO->value);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception If the transaction fails to commit
     */
    public function commit(): void
    {
        if ($this->getPdo(true)->commit() === false) {
            throw new Exception('The transaction failed to commit.', ExceptionCode::COMMIT_TRANSACTION_FAILED_IN_PDO->value);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception If the transaction fails to roll back
     */
    public function rollBack(): void
    {
        if ($this->getPdo(true)->rollBack() === false) {
            throw new Exception('The transaction failed to roll back.', ExceptionCode::ROLL_BACK_TRANSACTION_FAILED_IN_PDO->value);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function inTransaction(): bool
    {
        return $this->getPdo(true)->inTransaction();
    }

    /**
     * Hook for subclasses that resolve their connection parameters
     * lazily — invoked before every `getPdo()` call (and at the top of
     * `query()`, which reads the write parameters before connecting).
     * The default implementation is a no-op for subclasses that
     * initialise eagerly in their constructor (e.g. `MysqlPdoSqlService`).
     *
     * `MigrationPdoSqlService` overrides this hook so that
     * `MYSQL_WRITE_*` / `MYSQL_MIGRATION_*` env vars set by a migration /
     * seed command at `execute()` time (via
     * `MigrationConnectionTargetService`) take effect before the first PDO
     * connect — the alternative would have been to read env in the
     * constructor, which runs at container-build time before any CLI
     * option is parsed.
     *
     * @return void
     */
    protected function ensureInitialized(): void
    {
        // No-op default. Subclasses with lazy init override.
    }

    /**
     * Apply the active `DATABASE_PREFIX` to a connection's home schema (DSN
     * `dbname`).
     *
     * Under a prefix (test-DB isolation) the PDO connection's home schema
     * must resolve to a schema that actually EXISTS, so it has to point at
     * the prefixed schema too — otherwise it pins the unprefixed schema,
     * which does not exist on an ephemeral prefixed test instance, and the
     * connection fails with `SQLSTATE[HY000] [1049] Unknown database`.
     *
     * Empty prefix (dev / staging / prod runtime) is a no-op. An empty
     * `$database` (an intentionally schemaless connection) is left empty —
     * prefixing it would produce a bogus bare-prefix name.
     *
     * @param string $database Resolved connection database (may be empty)
     *
     * @return string `$database` with the active `DATABASE_PREFIX` applied, or unchanged when empty
     */
    protected function applyDatabasePrefix(string $database): string
    {
        if ($database === '') {
            return '';
        }

        return (string) getenv('DATABASE_PREFIX') . $database;
    }

    /**
     * The character set a MySQL/MariaDB connection speaks: `MYSQL_CHARSET`, default `utf8mb4`
     *
     * PHP strings are UTF-8, so the connection has to say so. Until v0.37 the
     * DSNs hardcoded `latin1`: the server then read every UTF-8 byte as a
     * latin1 character and stored non-ASCII text double-encoded in utf8mb4
     * columns -- an em dash became the three characters `â€”`. It round-tripped
     * through the same wrong setting, so the application never saw it; every
     * other reader of the database did. `MYSQL_CHARSET=latin1` keeps the old
     * behaviour for a host whose stored data still depends on it.
     *
     * @return string A charset name for the DSN
     *
     * @throws InvalidArgumentException When `MYSQL_CHARSET` is not a bare charset name
     */
    protected function connectionCharset(): string
    {
        $charset = strtolower(trim((string) getenv('MYSQL_CHARSET')));
        if ($charset === '') {
            return 'utf8mb4';
        }

        // It is interpolated into the DSN, so only a bare name is accepted.
        if (preg_match('/^[a-z0-9_]{1,32}$/', $charset) !== 1) {
            throw new InvalidArgumentException('MYSQL_CHARSET must be a charset name such as utf8mb4', ExceptionCode::INVALID_DATABASE_CHARSET->value);
        }

        return $charset;
    }

    /**
     * The session time zone a MySQL/MariaDB connection is put on: `MYSQL_TIME_ZONE`, default none
     *
     * Unset or empty leaves the server's own zone alone. Otherwise the value is
     * `SYSTEM`, an offset such as `+00:00` (-13:59 to +14:00), or a named zone such
     * as `America/New_York`. A named zone only works where the server has its
     * time-zone tables loaded; `SYSTEM` and offsets always work.
     *
     * @return ?string The zone to apply to each new connection, or null to leave the server default
     *
     * @throws InvalidArgumentException When `MYSQL_TIME_ZONE` is not a time zone name, `SYSTEM` or an offset
     */
    protected function connectionTimeZone(): ?string
    {
        $timeZone = trim((string) getenv('MYSQL_TIME_ZONE'));
        if ($timeZone === '') {
            return null;
        }

        if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $timeZone, $offset) === 1) {
            $minutes = ((int) $offset[2] * 60) + (int) $offset[3];
            $valid   = (int) $offset[3] < 60 && ($offset[1] === '+' ? $minutes <= 14 * 60 : ($minutes <= (13 * 60) + 59 && $minutes > 0)); // MySQL takes -13:59 to +14:00 and refuses -00:00
            if ($valid) {
                return $timeZone;
            }
        } elseif (strtoupper($timeZone) === 'SYSTEM' || preg_match('/^[A-Za-z][A-Za-z0-9_+\/-]{0,63}$/', $timeZone) === 1) { // Bound, never interpolated, but a malformed value should fail here by name
            return $timeZone;
        }

        throw new InvalidArgumentException(
            'MYSQL_TIME_ZONE must be SYSTEM, an offset such as +00:00, or a time zone name such as America/New_York',
            ExceptionCode::ERROR_CONNECTING_TO_PDO->value,
        );
    }

    /**
     * Put a new MySQL connection on `MYSQL_TIME_ZONE`, when one is set
     *
     * Runs `SET time_zone = ?` with the value bound. A failure is not swallowed:
     * the usual cause is a named zone on a server without time-zone tables.
     *
     * @param PDO $pdo A freshly opened MySQL connection
     *
     * @throws DtoException When the server refuses the time zone
     *
     * @return void
     */
    protected function applyConnectionTimeZone(PDO $pdo): void
    {
        $timeZone = $this->connectionTimeZone();
        if ($timeZone === null) {
            return;
        }

        try {
            $pdo->prepare('SET time_zone = ?')->execute([$timeZone]);
        } catch (PDOException $e) {
            throw new DtoException(
                'MYSQL_TIME_ZONE "' . $timeZone . '" could not be applied to the database connection. Named time zones (such as America/New_York) need the server\'s time-zone tables loaded; use SYSTEM or an offset such as +00:00 where they are not.',
                ExceptionCode::ERROR_CONNECTING_TO_PDO->value,
                $this->pdoExceptionToDto($e),
            );
        }
    }

    /**
     * Initialize the readPdoConstructorParameter and writePdoConstructorParameter objects
     *
     * @param string             $readDsn       For reads: The Data Source Name, or DSN, contains the information required to connect to the database
     * @param ?string            $readUsername  For reads: The user name for the DSN string. This parameter is optional for some PDO drivers. (optional) (default: null)
     * @param ?string            $readPassword  For reads: The password for the DSN string. This parameter is optional for some PDO drivers. (optional) (default: null)
     * @param ?array<int, mixed> $readOptions   For reads: A key=>value array of driver-specific connection options (optional) (default: null)
     * @param ?string            $writeDsn      For writes: The Data Source Name, or DSN, contains the information required to connect to the database (read parameters will be used for writes if this parameter is omitted) (optional) (default: null)
     * @param ?string            $writeUsername For writes: The user name for the DSN string. This parameter is optional for some PDO drivers. (optional) (default: null)
     * @param ?string            $writePassword For writes: The password for the DSN string. This parameter is optional for some PDO drivers. (optional) (default: null)
     * @param ?array<int, mixed> $writeOptions  For writes: A key=>value array of driver-specific connection options (optional) (default: null)
     *
     * @return void
     */
    protected function initializePdoConstructorParameters(
        string $readDsn,
        ?string $readUsername = null,
        ?string $readPassword = null,
        ?array $readOptions = null,
        ?string $writeDsn = null,
        ?string $writeUsername = null,
        ?string $writePassword = null,
        ?array $writeOptions = null,
    ): void {
        $readPdoConstructorParameters = new PdoConstructorParameters(
            dsn:      $readDsn,
            username: $readUsername,
            password: $readPassword,
            options:  $readOptions,
        );

        $this->readPdoConstructorParameters = $readPdoConstructorParameters;

        $this->writePdoConstructorParameters = $writeDsn === null ? $readPdoConstructorParameters : new PdoConstructorParameters(
            dsn:      $writeDsn,
            username: $writeUsername,
            password: $writePassword,
            options:  $writeOptions,
        );
    }

    /**
     * Get a PDO object
     *
     * @param bool $useWrite Whether or not to use the write PDO or the read PDO (optional) (default: false)
     *
     * @throws DtoException When the database connection fails (with a \Ubix\DataTransferObject\PdoError DTO)
     *
     * @return PDO A PDO database instance
     */
    private function getPdo(bool $useWrite = false): PDO
    {
        $this->ensureInitialized();

        try {
            $singletonKey = md5(serialize($useWrite ? $this->writePdoConstructorParameters : $this->readPdoConstructorParameters));

            if (!isset(self::$singletons[$singletonKey])) {
                $pdo = new PDO(
                    $useWrite ? $this->writePdoConstructorParameters->dsn : $this->readPdoConstructorParameters->dsn,
                    $useWrite ? $this->writePdoConstructorParameters->username : $this->readPdoConstructorParameters->username,
                    $useWrite ? $this->writePdoConstructorParameters->password : $this->readPdoConstructorParameters->password,
                    $useWrite ? $this->writePdoConstructorParameters->options : $this->readPdoConstructorParameters->options,
                );

                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $this->applyConnectionTimeZone($pdo); // Throws before the connection is cached, so a refused zone is never silently skipped
                }

                self::$singletons[$singletonKey] = $pdo;
            }

            return self::$singletons[$singletonKey];
        } catch (PDOException $e) {
            throw new DtoException(
                'The database connection failed.',
                ExceptionCode::ERROR_CONNECTING_TO_PDO->value,
                $this->pdoExceptionToDto($e),
            );
        }
    }

    /**
     * Get a PDO statement by executing a SQL query
     *
     * @param bool                                          $useWrite            Whether or not to use the write PDO or the read PDO
     * @param string                                        $sql                 The SQL query
     * @param array<int|string, bool|int|float|string|null> $parameters          An array of named parameters (optional)
     * @param bool                                          $allowParameterReuse Whether or not to support reusing parameters (optional) (default: false)
     *
     * @throws DtoException When the query fails (with a \Ubix\DataTransferObject\PdoError DTO object)
     *
     * @return PDOStatement This method will always return a PDOStatement, if there is a problem executing the query then an exception will be thrown
     */
    private function getPdoStatement(bool $useWrite, string $sql, array $parameters = [], bool $allowParameterReuse = false): PDOStatement
    {
        try {
            //
            //  If the $allowParameterReuse flag is set to true then go through the query and manually split up any reused parameters
            //
            if ($allowParameterReuse) {
                foreach ($parameters as $parameter => $value) {
                    if (is_string($parameter)) {
                        $pattern = '/:' . preg_quote($parameter, '/') . '(?=$|[^\p{L}\p{N}_])/u'; // Match :parameter and make sure the next character is either the end of the string or a character that isn't a letter, number or underscore (including Unicode characters)

                        $parameterCount = preg_match_all($pattern, $sql);
                        if ($parameterCount > 1) { // Only do these replacements if there are multiple occurences of :parameter in the SQL query
                            //
                            //  Replace each occurrence of :parameter with :parameter_i and update $parameters accordingly
                            //
                            for ($i = 1; $i <= $parameterCount; $i++) {
                                $newParameter = $parameter . '_' . $i; // Determine the new parameter name

                                $sql = preg_replace($pattern, ':' . $newParameter, $sql, 1); // Replace the next occurence of :parameter in the SQL query with :parameter_i
                                assert(is_string($sql));

                                $parameters[$newParameter] = $value; // Add the new parameter to the $parameters array
                            }

                            //
                            //  Remove the original parameter
                            //
                            unset($parameters[$parameter]);
                        }
                    }
                }
            }

            //
            //  Prepare and execute the query
            //
            $query = $this->getPdo($useWrite)->prepare($sql);
            $query->execute($parameters);
            return $query;
        } catch (PDOException $e) {
            throw new DtoException(
                'The query execution failed.',
                ExceptionCode::ERROR_EXECUTING_PDO_QUERY->value,
                $this->pdoExceptionToDto($e, $sql, $parameters),
            );
        }
    }

    /**
     * Convert a PDOException into a PdoError DTO
     *
     * @param PDOException                                   $e          The PDO exception
     * @param ?string                                        $sql        The SQL query (optional)
     * @param ?array<int|string, bool|int|float|string|null> $parameters An array of named parameters (optional)
     *
     * @return PdoError The PdoError DTO
     */
    private function pdoExceptionToDto(PDOException $e, ?string $sql = null, ?array $parameters = null): PdoError
    {
        //
        //  We have to do some work to get the correct $sqlState, $driverCode and $driver_messsage values because despite what the type hints claim `$e->getCode()` may return a string, e.g. "HY093"
        //
        $sqlState = isset($e->errorInfo[0]) && is_string($e->errorInfo[0]) ? $e->errorInfo[0] : null;

        $driverCode = null;
        if (isset($e->errorInfo[1]) && is_int($e->errorInfo[1])) {
            $driverCode = (string)$e->errorInfo[1];
        } elseif (isset($e->errorInfo[1]) && is_string($e->errorInfo[1])) {
            $driverCode = $e->errorInfo[1];
        } elseif (is_int($e->getCode())) {
            $driverCode = (string)$e->getCode();
        } elseif (is_string($e->getCode())) {
            $driverCode = $e->getCode();
        }

        $driverMessage = isset($e->errorInfo[2]) && is_string($e->errorInfo[2]) ? $e->errorInfo[2] : $e->getMessage();

        //
        //  Return a PdoError DTO
        //
        return new PdoError(
            sqlState:      $sqlState,
            driverCode:    $driverCode,
            driverMessage: $driverMessage,
            sql:           $sql,
            parameters:    $parameters,
        );
    }
}
