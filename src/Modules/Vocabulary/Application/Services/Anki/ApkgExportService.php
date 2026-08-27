<?php

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services\Anki;

use Lwt\Modules\Language\Domain\LanguageRepositoryInterface;
use Lwt\Modules\Language\Infrastructure\MySqlLanguageRepository;
use Lwt\Modules\Review\Domain\TermScheduleRepositoryInterface;
use Lwt\Modules\Review\Infrastructure\MySqlTermScheduleRepository;
use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgDeck;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgWriter;
use Lwt\Modules\Vocabulary\Infrastructure\MySqlTermRepository;
use RuntimeException;

/**
 * Orchestrates building an .apkg from one LWT language's terms.
 *
 * Pulls the terms via the term repository, looks up tags through
 * TermTagService, runs them through ApkgTermMapper, then hands the resulting
 * notes to ApkgWriter. The writer is the only piece that knows the Anki
 * binary layout; this service is just data assembly.
 */
final class ApkgExportService
{
    public function __construct(
        private readonly TermRepositoryInterface $terms,
        private readonly LanguageRepositoryInterface $languages,
        private readonly ApkgWriter $writer,
        private readonly TermScheduleRepositoryInterface $schedules,
    ) {
    }

    public static function default(): self
    {
        return new self(
            new MySqlTermRepository(),
            new MySqlLanguageRepository(),
            new ApkgWriter(),
            new MySqlTermScheduleRepository(),
        );
    }

    /**
     * Export every term in a language. Convenience wrapper around exportTerms().
     */
    public function exportLanguage(int $languageId, string $outputPath): ExportResult
    {
        return $this->exportTerms($languageId, null, $outputPath);
    }

    /**
     * Export a language's terms, optionally restricted to a specific subset.
     *
     * Subset semantics: any IDs not belonging to $languageId are silently
     * skipped, so a stale browser-side selection won't leak terms across
     * languages. Re-importing a subset .apkg only updates the terms whose
     * guids it contains; LWT terms outside the subset are untouched, which
     * matches what users want when they only studied a slice in Anki.
     *
     * @param list<int>|null $termIds Subset to include; null = whole language
     */
    public function exportTerms(int $languageId, ?array $termIds, string $outputPath): ExportResult
    {
        $language = $this->languages->find($languageId);
        if ($language === null) {
            throw new RuntimeException("Language {$languageId} not found");
        }

        $allTerms = $this->terms->findByLanguage($languageId);
        if ($termIds !== null && $termIds !== []) {
            $idSet = array_flip($termIds);
            $terms = array_values(array_filter(
                $allTerms,
                static fn($t) => isset($idSet[$t->id()->toInt()])
            ));
        } else {
            $terms = $allTerms;
        }

        if ($terms === []) {
            $detail = ($termIds !== null && $termIds !== [])
                ? "selected terms" : "language '{$language->name()}'";
            throw new RuntimeException("No terms to export for {$detail}");
        }

        $deck = ApkgDeck::forLanguage($languageId, $language->name());

        $termIdList = array_values(array_map(
            static fn($t) => $t->id()->toInt(),
            $terms
        ));
        $schedules = $this->schedules->findMany($termIdList);
        $history = $this->schedules->historyFor($termIdList);

        $notes = [];
        $suspended = 0;
        $scheduled = 0;
        foreach ($terms as $term) {
            $termId = $term->id()->toInt();
            $tagNames = array_values(TermTagService::getWordTagsArray($termId));
            $note = ApkgTermMapper::termToNote($term, $tagNames);

            $state = $schedules[$termId] ?? null;
            if ($state !== null) {
                $note = $note->withSchedule(
                    ApkgTermMapper::stateToSchedule($state, $history[$termId] ?? [])
                );
                $scheduled++;
            }

            $notes[] = $note;
            if ($note->suspended) {
                $suspended++;
            }
        }

        // Writer requires a non-empty list; guarded above.
        /** @var non-empty-list<ApkgNote> $notes */
        $this->writer->write($outputPath, $deck, $notes);

        return new ExportResult(
            outputPath: $outputPath,
            languageId: $languageId,
            languageName: $language->name(),
            deckName: $deck->name,
            noteCount: count($notes),
            suspendedCount: $suspended,
        );
    }
}
