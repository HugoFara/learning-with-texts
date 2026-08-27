<?php

/**
 * MySQL Term Repository
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Infrastructure;

use DateTimeImmutable;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermId;

/**
 * MySQL implementation of Term Repository.
 *
 * Provides database access for vocabulary/word management operations.
 * Handles both basic CRUD and term-specific queries.
 *
 * Query methods (find/search/pagination) are in TermQueryMethods trait.
 * Stats/update/bulk methods are in TermStatsMethods trait.
 *
 * @since 3.0.0
 */
class MySqlTermRepository implements TermRepositoryInterface
{
    use TermQueryMethods;
    use TermStatsMethods;

    /**
     * @var string Table name without prefix
     */
    protected string $tableName = 'words';

    /**
     * @var string Primary key column
     */
    protected string $primaryKey = 'WoID';

    /**
     * @var array<string, string> Property to column mapping
     */
    protected array $columnMap = [
        'id' => 'WoID',
        'languageId' => 'WoLgID',
        'text' => 'WoText',
        'textLowercase' => 'WoTextLC',
        'lemma' => 'WoLemma',
        'lemmaLc' => 'WoLemmaLC',
        'status' => 'WoStatus',
        'translation' => 'WoTranslation',
        'sentence' => 'WoSentence',
        'notes' => 'WoNotes',
        'romanization' => 'WoRomanization',
        'wordCount' => 'WoWordCount',
        'createdAt' => 'WoCreated',
        'statusChangedAt' => 'WoStatusChanged',
    ];

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
     * Map a database row to a Term entity.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return Term
     */
    protected function mapToEntity(array $row): Term
    {
        return Term::reconstitute(
            (int) $row['WoID'],
            (int) $row['WoLgID'],
            (string) $row['WoText'],
            (string) $row['WoTextLC'],
            isset($row['WoLemma']) && $row['WoLemma'] !== '' ? (string) $row['WoLemma'] : null,
            isset($row['WoLemmaLC']) && $row['WoLemmaLC'] !== '' ? (string) $row['WoLemmaLC'] : null,
            (int) $row['WoStatus'],
            (string) ($row['WoTranslation'] ?? ''),
            (string) ($row['WoSentence'] ?? ''),
            (string) ($row['WoNotes'] ?? ''),
            (string) ($row['WoRomanization'] ?? ''),
            (int) ($row['WoWordCount'] ?? 1),
            $this->parseDateTime(isset($row['WoCreated']) ? (string)$row['WoCreated'] : null),
            $this->parseDateTime(isset($row['WoStatusChanged']) ? (string)$row['WoStatusChanged'] : null)
        );
    }

    /**
     * Map a Term entity to a database row.
     *
     * @param Term $term The term entity
     *
     * @return array<string, null|scalar> Database column => value pairs
     */
    protected function mapToRow(Term $term): array
    {
        return [
            'WoID' => $term->id()->toInt(),
            'WoLgID' => $term->languageId()->toInt(),
            'WoText' => $term->text(),
            'WoTextLC' => $term->textLowercase(),
            'WoLemma' => $term->lemma(),
            'WoLemmaLC' => $term->lemmaLc(),
            'WoStatus' => $term->status()->toInt(),
            'WoTranslation' => $term->translation(),
            'WoSentence' => $term->sentence(),
            'WoNotes' => $term->notes(),
            'WoRomanization' => $term->romanization(),
            'WoWordCount' => $term->wordCount(),
            'WoCreated' => $term->createdAt()->format('Y-m-d H:i:s'),
            'WoStatusChanged' => $term->statusChangedAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Parse a datetime string into DateTimeImmutable.
     *
     * @param string|null $datetime The datetime string
     *
     * @return DateTimeImmutable
     */
    private function parseDateTime(?string $datetime): DateTimeImmutable
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return new DateTimeImmutable();
        }
        return new DateTimeImmutable($datetime);
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Term
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
     */
    public function save(Term $term): int
    {
        if ($term->id()->isNew()) {
            // Insert new term
            $data = $this->mapToRow($term);
            unset($data['WoID']); // Remove ID for insert

            $id = (int) $this->query()->insertPrepared($data);
            $term->setId(TermId::fromInt($id));
            return $id;
        } else {
            // Update existing term
            $data = $this->mapToRow($term);
            unset($data['WoID']);

            $this->query()
                ->where('WoID', '=', $term->id()->toInt())
                ->updatePrepared($data);
            return $term->id()->toInt();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        $affected = $this->query()
            ->where('WoID', '=', $id)
            ->deletePrepared();

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(int $id): bool
    {
        return $this->query()
            ->where('WoID', '=', $id)
            ->existsPrepared();
    }

    /**
     * Count terms matching criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs
     *
     * @return int The count
     */
    public function count(array $criteria = []): int
    {
        $query = $this->query();

        /**
         * @var string $field
         * @var mixed $value
         */
        foreach ($criteria as $field => $value) {
            $column = $this->columnMap[$field] ?? $field;
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($value === null) {
                $query->whereNull($column);
            } elseif (is_scalar($value)) {
                $query->where($column, '=', $value);
            }
        }

        return $query->countPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function findByLanguage(
        int $languageId,
        ?string $orderBy = 'WoText',
        string $direction = 'ASC'
    ): array {
        $rows = $this->query()
            ->where('WoLgID', '=', $languageId)
            ->orderBy($orderBy ?? 'WoText', $direction)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByTextLc(int $languageId, string $textLc): ?Term
    {
        $row = $this->query()
            ->where('WoLgID', '=', $languageId)
            ->where('WoTextLC', '=', $textLc)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function termExists(int $languageId, string $textLc, ?int $excludeId = null): bool
    {
        $query = $this->query()
            ->where('WoLgID', '=', $languageId)
            ->where('WoTextLC', '=', $textLc);

        if ($excludeId !== null) {
            $query->where('WoID', '!=', $excludeId);
        }

        return $query->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function countByLanguage(int $languageId): int
    {
        return $this->query()
            ->where('WoLgID', '=', $languageId)
            ->countPrepared();
    }
}
