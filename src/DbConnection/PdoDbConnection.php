<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnection;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * PDO adapter for unified database connection interface.
 *
 * This adapter wraps a PDO instance and provides a consistent interface
 * for database operations across different database drivers.
 */
final class PdoDbConnection implements
    DbConnectionInterface
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @inheritDoc
     */
    public function executeAndFetchColumn(string $sql, array $parameters = []): mixed
    {
        $statement = $this->prepareAndExecute($sql, $parameters);

        $result = $statement->fetchColumn(0);

        // PDO fetchColumn returns false when no rows are found
        // We need to distinguish between false as a value and no result
        if ($result === false && $statement->rowCount() === 0) {
            return null;
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function isInTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Prepare and execute a SQL statement with parameters.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $parameters Parameters to bind to the query
     * @return PDOStatement The executed statement
     * @throws RuntimeException If statement preparation or execution fails
     */
    private function prepareAndExecute(string $sql, array $parameters): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);

            if ($statement === false) {
                $errorInfo = $this->pdo->errorInfo();
                throw new RuntimeException(
                    sprintf(
                        'Failed to prepare SQL statement: %s. Error: %s',
                        $sql,
                        $errorInfo[2] ?? 'Unknown error',
                    ),
                );
            }

            $success = $statement->execute($parameters);

            if (!$success) {
                $errorInfo = $statement->errorInfo();
                throw new RuntimeException(
                    sprintf(
                        'Failed to execute SQL statement: %s. Error: %s',
                        $sql,
                        $errorInfo[2] ?? 'Unknown error',
                    ),
                );
            }

            return $statement;
        } catch (\PDOException $e) {
            throw new RuntimeException(
                sprintf(
                    'PDO error while executing SQL statement: %s. Error: %s',
                    $sql,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }
}
