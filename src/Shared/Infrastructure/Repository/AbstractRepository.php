<?php

/**
 * Abstract Repository
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Repository
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Repository;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;

/**
 * Abstract base class for repositories.
 *
 * Provides common CRUD functionality using the QueryBuilder with
 * prepared statements for security.
 *
 * @template T The entity type this repository manages
 *
 * @implements RepositoryInterface<T>
 *
 * @since 3.0.0
 */
abstract class AbstractRepository implements RepositoryInterface
{
    /**
     * The table name (without prefix).
     *
     * @var string
     */
    protected string $tableName;

    /**
     * The primary key column name.
     *
     * @var string
     */
    protected string $primaryKey = 'id';

    /**
     * Column mapping: entity property => database column.
     *
     * @var array<string, string>
     */
    protected array $columnMap = [];

    /**
     * Get a query builder for this repository's table.
     *
     * @return QueryBuilder
     */
    protected function query(): QueryBuilder
    {
        return QueryBuilder::table($this->tableName);
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-suppress InvalidReturnStatement
     */
    public function find(int $id): ?object
    {
        $row = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        return $this->mapToEntity($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(): array
    {
        $rows = $this->query()->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     */
    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $query = $this->query();

        /** @var mixed $value */
        foreach ($criteria as $field => $value) {
            $column = $this->columnMap[$field] ?? $field;
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, '=', $value);
            }
        }

        if ($orderBy !== null) {
            foreach ($orderBy as $field => $direction) {
                $column = $this->columnMap[$field] ?? $field;
                $query->orderBy($column, $direction);
            }
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        $rows = $query->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-suppress InvalidReturnStatement
     *
     * @param array<string, mixed> $criteria
     *
     * @return object|null
     * @psalm-return T|null
     */
    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1);

        /** @psalm-var T|null */
        return $results[0] ?? null;
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     * @psalm-suppress PossiblyUnusedReturnValue - Return value is optional; useful for inserts
     */
    public function save(object $entity): int
    {
        $data = $this->mapToRow($entity);
        $id = $this->getEntityId($entity);

        if ($id > 0) {
            // Update existing
            $query = $this->query()->where($this->primaryKey, '=', $id);
            $query->updatePrepared($data);
            return $id;
        }

        // Insert new
        $insertData = $data;
        unset($insertData[$this->primaryKey]); // Remove ID for auto-increment

        $newId = (int) $this->query()->insertPrepared($insertData);
        $this->setEntityId($entity, $newId);

        return $newId;
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function delete(object|int $entityOrId): bool
    {
        $id = is_int($entityOrId) ? $entityOrId : $this->getEntityId($entityOrId);

        if ($id <= 0) {
            return false;
        }

        $deleted = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->deletePrepared();

        return $deleted > 0;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        $query = $this->query();

        /** @var mixed $value */
        foreach ($criteria as $field => $value) {
            $column = $this->columnMap[$field] ?? $field;
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, '=', $value);
            }
        }

        return $query->countPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function exists(int $id): bool
    {
        return $this->query()
            ->where($this->primaryKey, '=', $id)
            ->existsPrepared();
    }

    /**
     * Map a database row to an entity object.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return object The entity
     *
     * @psalm-return T
     */
    abstract protected function mapToEntity(array $row): object;

    /**
     * Map an entity to a database row.
     *
     * @param object $entity The entity
     *
     * @return array<string, scalar|null> Database row data
     *
     * @psalm-param T $entity
     */
    abstract protected function mapToRow(object $entity): array;

    /**
     * Get the ID from an entity.
     *
     * @param object $entity The entity
     *
     * @return int The entity ID
     *
     * @psalm-param T $entity
     */
    abstract protected function getEntityId(object $entity): int;

    /**
     * Set the ID on an entity.
     *
     * @param object $entity The entity
     * @param int    $id     The ID to set
     *
     * @return void
     *
     * @psalm-param T $entity
     */
    abstract protected function setEntityId(object $entity, int $id): void;

    /**
     * Begin a database transaction.
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return mysqli_begin_transaction(Connection::getInstance());
    }

    /**
     * Commit the current transaction.
     *
     * @return bool
     */
    public function commit(): bool
    {
        return mysqli_commit(Connection::getInstance());
    }

    /**
     * Rollback the current transaction.
     *
     * @return bool
     */
    public function rollback(): bool
    {
        return mysqli_rollback(Connection::getInstance());
    }
}
