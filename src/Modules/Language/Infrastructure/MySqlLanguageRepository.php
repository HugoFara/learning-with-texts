<?php

/**
 * MySQL Language Repository
 *
 * Infrastructure adapter for language persistence using MySQL.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Infrastructure;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Modules\Language\Domain\Language;
use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;

/**
 * MySQL implementation of LanguageRepositoryInterface.
 *
 * Provides database access for language management operations.
 *
 * @since 3.0.0
 */
class MySqlLanguageRepository implements LanguageRepositoryInterface
{
    /**
     * @var string Table name without prefix
     */
    private string $tableName = 'languages';

    /**
     * @var string Primary key column
     */
    private string $primaryKey = 'LgID';

    /**
     * @var array<string, string> Property to column mapping
     */
    private array $columnMap = [
        'id' => 'LgID',
        'name' => 'LgName',
        'dict1uri' => 'LgDict1URI',
        'dict2uri' => 'LgDict2URI',
        'translator' => 'LgGoogleTranslateURI',
        'dict1popup' => 'LgDict1PopUp',
        'dict2popup' => 'LgDict2PopUp',
        'translatorpopup' => 'LgGoogleTranslatePopUp',
        'sourcelang' => 'LgSourceLang',
        'targetlang' => 'LgTargetLang',
        'exporttemplate' => 'LgExportTemplate',
        'textsize' => 'LgTextSize',
        'charactersubst' => 'LgCharacterSubstitutions',
        'regexpsplitsent' => 'LgRegexpSplitSentences',
        'exceptionsplitsent' => 'LgExceptionsSplitSentences',
        'regexpwordchar' => 'LgRegexpWordCharacters',
        'parsertype' => 'LgParserType',
        'removespaces' => 'LgRemoveSpaces',
        'spliteachchar' => 'LgSplitEachChar',
        'rightoleft' => 'LgRightToLeft',
        'ttsvoiceapi' => 'LgTTSVoiceAPI',
        'showromanization' => 'LgShowRomanization',
        'localdictmode' => 'LgLocalDictMode',
    ];

    /**
     * Get a query builder for this repository's table.
     *
     * @return QueryBuilder
     */
    private function query(): QueryBuilder
    {
        return QueryBuilder::table($this->tableName);
    }

    /**
     * Map a database row to a Language entity.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return Language
     */
    private function mapToEntity(array $row): Language
    {
        return Language::reconstitute(
            (int) $row['LgID'],
            (string) $row['LgName'],
            (string) $row['LgDict1URI'],
            (string) ($row['LgDict2URI'] ?? ''),
            (string) ($row['LgGoogleTranslateURI'] ?? ''),
            (bool) ($row['LgDict1PopUp'] ?? false),
            (bool) ($row['LgDict2PopUp'] ?? false),
            (bool) ($row['LgGoogleTranslatePopUp'] ?? false),
            isset($row['LgSourceLang']) && $row['LgSourceLang'] !== '' ? (string) $row['LgSourceLang'] : null,
            isset($row['LgTargetLang']) && $row['LgTargetLang'] !== '' ? (string) $row['LgTargetLang'] : null,
            (string) ($row['LgExportTemplate'] ?? ''),
            (int) ($row['LgTextSize'] ?? 100),
            (string) ($row['LgCharacterSubstitutions'] ?? ''),
            (string) $row['LgRegexpSplitSentences'],
            (string) ($row['LgExceptionsSplitSentences'] ?? ''),
            (string) $row['LgRegexpWordCharacters'],
            (bool) ($row['LgRemoveSpaces'] ?? false),
            (bool) ($row['LgSplitEachChar'] ?? false),
            (bool) ($row['LgRightToLeft'] ?? false),
            (string) ($row['LgTTSVoiceAPI'] ?? ''),
            (bool) ($row['LgShowRomanization'] ?? false),
            isset($row['LgParserType']) ? (string) $row['LgParserType'] : null,
            (int) ($row['LgLocalDictMode'] ?? 0)
        );
    }

    /**
     * Map a Language entity to database row.
     *
     * @param Language $entity The language entity
     *
     * @return array<string, scalar|null>
     */
    private function mapToRow(Language $entity): array
    {
        return [
            'LgID' => $entity->id()->toInt(),
            'LgName' => $entity->name(),
            'LgDict1URI' => $entity->dict1Uri(),
            'LgDict2URI' => $entity->dict2Uri(),
            'LgGoogleTranslateURI' => $entity->translatorUri(),
            'LgExportTemplate' => $entity->exportTemplate(),
            'LgTextSize' => $entity->textSize(),
            'LgCharacterSubstitutions' => $entity->characterSubstitutions(),
            'LgRegexpSplitSentences' => $entity->regexpSplitSentences(),
            'LgExceptionsSplitSentences' => $entity->exceptionsSplitSentences(),
            'LgRegexpWordCharacters' => $entity->regexpWordCharacters(),
            'LgParserType' => $entity->parserType(),
            'LgRemoveSpaces' => (int) $entity->removeSpaces(),
            'LgSplitEachChar' => (int) $entity->splitEachChar(),
            'LgRightToLeft' => (int) $entity->rightToLeft(),
            'LgTTSVoiceAPI' => $entity->ttsVoiceApi(),
            'LgShowRomanization' => (int) $entity->showRomanization(),
            'LgLocalDictMode' => $entity->localDictMode(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Language
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
    public function save(Language $entity): void
    {
        $data = $this->mapToRow($entity);
        $id = $entity->id()->toInt();

        if ($id > 0 && !$entity->id()->isNew()) {
            // Update existing
            $query = $this->query()->where($this->primaryKey, '=', $id);
            $query->updatePrepared($data);
            return;
        }

        // Insert new
        $insertData = $data;
        unset($insertData[$this->primaryKey]); // Remove ID for auto-increment

        $newId = (int) $this->query()->insertPrepared($insertData);
        $entity->setId(LanguageId::fromInt($newId));
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $this->query()
            ->where($this->primaryKey, '=', $id)
            ->deletePrepared();
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
     * {@inheritdoc}
     */
    public function findAllActive(string $orderBy = 'LgName', string $direction = 'ASC'): array
    {
        $rows = $this->query()
            ->where('LgName', '!=', '')
            ->orderBy($orderBy, $direction)
            ->getPrepared();

        return array_map(
            fn(array $row) => $this->mapToEntity($row),
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByName(string $name): ?Language
    {
        $row = $this->query()
            ->where('LgName', '=', $name)
            ->firstPrepared();

        return $row !== null ? $this->mapToEntity($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $query = $this->query()->where('LgName', '=', $name);

        if ($excludeId !== null) {
            $query->where('LgID', '!=', $excludeId);
        }

        return $query->existsPrepared();
    }

    /**
     * {@inheritdoc}
     */
    public function getAllAsDict(): array
    {
        $languages = [];
        $rows = $this->query()
            ->select(['LgID', 'LgName'])
            ->where('LgName', '!=', '')
            ->getPrepared();

        foreach ($rows as $row) {
            $languages[(string) $row['LgName']] = (int) $row['LgID'];
        }

        return $languages;
    }

    /**
     * {@inheritdoc}
     */
    public function getForSelect(int $maxNameLength = 30): array
    {
        $result = [];
        $rows = $this->query()
            ->select(['LgID', 'LgName'])
            ->where('LgName', '!=', '')
            ->orderBy('LgName')
            ->getPrepared();

        foreach ($rows as $row) {
            $name = (string) $row['LgName'];
            if (strlen($name) > $maxNameLength) {
                $name = substr($name, 0, $maxNameLength) . '...';
            }
            $result[] = [
                'id' => (int) $row['LgID'],
                'name' => $name,
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function findEmptyLanguageId(): ?int
    {
        $row = $this->query()
            ->select('LgID')
            ->where('LgName', '=', '')
            ->orderBy('LgID')
            ->limit(1)
            ->firstPrepared();

        return $row !== null ? (int) $row['LgID'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function isRightToLeft(int $id): bool
    {
        $row = $this->query()
            ->select('LgRightToLeft')
            ->where('LgID', '=', $id)
            ->firstPrepared();

        return $row !== null && (bool) $row['LgRightToLeft'];
    }

    /**
     * {@inheritdoc}
     */
    public function getWordCharacters(int $id): ?string
    {
        $row = $this->query()
            ->select('LgRegexpWordCharacters')
            ->where('LgID', '=', $id)
            ->firstPrepared();

        return $row !== null ? (string) $row['LgRegexpWordCharacters'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function createEmpty(): Language
    {
        return Language::create(
            'New Language',
            '',
            '.!?',
            'a-zA-Z'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName(int $id): ?string
    {
        $row = $this->query()
            ->select('LgName')
            ->where('LgID', '=', $id)
            ->firstPrepared();

        return $row !== null ? (string) $row['LgName'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getTranslatorUri(int $id): ?string
    {
        $row = $this->query()
            ->select('LgGoogleTranslateURI')
            ->where('LgID', '=', $id)
            ->firstPrepared();

        return $row !== null ? (string) $row['LgGoogleTranslateURI'] : null;
    }
}
