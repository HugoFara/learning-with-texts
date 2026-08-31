<?php

/**
 * Round-trip LWT's .apkg through a real Anki collection.
 *
 * The rest of the .apkg test suite has LWT on both ends: LWT writes a file and
 * LWT reads it back, and the two agree. They agreed all the way through the
 * period when the round trip was broken -- Anki 26.08 exports a compressed
 * collection by default and leaves a one-note stub for older clients, LWT read
 * the stub, and the import reported success over a file it had not understood
 * (#264). Nothing in a suite where LWT is both writer and reader can catch
 * that. This can, because the other end is Anki.
 *
 * Usage:
 *   php bin/lwt-apkg-oracle.php                       # skips if Anki is absent
 *   ANKI_PYTHON=.venv-anki/bin/python php bin/lwt-apkg-oracle.php
 *
 * Setup:
 *   python3 -m venv .venv-anki && .venv-anki/bin/pip install anki
 *
 * Exit codes: 0 pass (or skipped), 1 a check failed. Skipping is a pass on
 * purpose -- Anki's Python library is far too heavy to require of anyone
 * running `composer test`, and a suite that fails when an optional tool is
 * missing gets removed from CI rather than fixed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgDeck;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgNote;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReader;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgReview;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgSchedule;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ApkgWriter;

const ORACLE_SCRIPT = __DIR__ . '/../scripts/anki/anki_oracle.py';
const ANKI_UNAVAILABLE = 3;

$failures = 0;
$workdir = sys_get_temp_dir() . '/lwt-apkg-oracle-' . getmypid();
@mkdir($workdir, 0700, true);

/**
 * Report a check.
 */
$check = static function (bool $ok, string $what) use (&$failures): bool {
    fwrite($ok ? STDOUT : STDERR, ($ok ? "  [OK] " : "  [FAIL] ") . $what . "\n");
    if (!$ok) {
        $failures++;
    }
    return $ok;
};

/**
 * Run the oracle, returning [exitCode, stdout, stderr].
 *
 * @param list<string> $args
 *
 * @return array{0: int, 1: string, 2: string}
 */
$runOracle = static function (array $args) use ($workdir): array {
    $python = getenv('ANKI_PYTHON');
    if (!is_string($python) || $python === '') {
        $python = 'python3';
    }

    $command = array_merge([$python, ORACLE_SCRIPT], $args);
    $escaped = implode(' ', array_map('escapeshellarg', $command));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open($escaped, $descriptors, $pipes, $workdir);
    if (!is_resource($process)) {
        return [1, '', 'could not start ' . $python];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
};

// ---------------------------------------------------------------------------
// Is there an Anki to ask?
// ---------------------------------------------------------------------------

[$code, $version, $stderr] = $runOracle(['check']);
if ($code === ANKI_UNAVAILABLE) {
    fwrite(STDOUT, "[SKIP] Anki's Python library is not available.\n");
    fwrite(STDOUT, trim($stderr) . "\n");
    exit(0);
}
if ($code !== 0) {
    fwrite(STDERR, "[FAIL] could not run the oracle script:\n" . $stderr . "\n");
    exit(1);
}
$version = trim($version);
fwrite(STDOUT, "Anki {$version}\n");

// ---------------------------------------------------------------------------
// The seed: one term with real history, one never studied.
// ---------------------------------------------------------------------------

// The studied card is due in the past and the other is suspended, so exactly
// one card is on offer and the answer below is not at the mercy of how Anki
// happens to interleave new and review cards.
$reviewedAt = new DateTimeImmutable('-3 days 09:00:00');
$due = new DateTimeImmutable('-1 day 00:00:00');

$seedNotes = [
    new ApkgNote(
        lwtTermId: 4242,
        term: 'perro',
        translation: 'dog',
        romanization: '',
        notes: 'the studied one',
        tags: ['animal'],
        suspended: false,
        schedule: new ApkgSchedule(
            stability: 7.5,
            difficulty: 5.25,
            desiredRetention: 0.9,
            due: $due,
            intervalDays: 7,
            reps: 2,
            lapses: 0,
            reviews: [
                new ApkgReview(new DateTimeImmutable('-10 days 09:00:00'), 3, 3, 0),
                new ApkgReview($reviewedAt, 3, 7, 3),
            ],
        ),
    ),
    new ApkgNote(
        lwtTermId: 4243,
        term: 'gato',
        translation: 'cat',
        romanization: '',
        notes: 'the new one',
        tags: [],
        suspended: true,
        schedule: null,
    ),
];

$seedPath = $workdir . '/seed.apkg';
(new ApkgWriter())->write($seedPath, ApkgDeck::forLanguage(7, 'Spanish'), $seedNotes);
fwrite(STDOUT, sprintf("Seeded %s (%d bytes)\n", $seedPath, (int) filesize($seedPath)));

/**
 * Read the report the oracle wrote.
 *
 * @return array<string, mixed>
 */
$readReport = static function (string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
};

// ---------------------------------------------------------------------------
// 1. Anki accepts what LWT writes, and keeps its schedule.
// ---------------------------------------------------------------------------

fwrite(STDOUT, "\nAnki reads what LWT wrote\n");

$legacyOut = $workdir . '/legacy.apkg';
$legacyReport = $workdir . '/legacy.json';
[$code, , $stderr] = $runOracle([
    'roundtrip',
    '--in', $seedPath,
    '--out', $legacyOut,
    '--report', $legacyReport,
    '--answer', '4',
]);
if (!$check($code === 0, 'Anki imported, answered and re-exported' . ($code === 0 ? '' : ": {$stderr}"))) {
    exit(1);
}

$report = $readReport($legacyReport);
/** @var array{note_count?: int, revlog_count?: int, notes?: list<array<string, mixed>>} $afterImport */
$afterImport = is_array($report['after_import'] ?? null) ? $report['after_import'] : [];
$check(($afterImport['note_count'] ?? 0) === 2, 'both notes arrived in Anki');
$check(($afterImport['revlog_count'] ?? 0) === 2, "both reviews arrived (Anki's own revlog)");

$guids = array_map(
    static fn(array $note): string => (string) ($note['guid'] ?? ''),
    $afterImport['notes'] ?? []
);
$check(
    in_array('lwt-4242', $guids, true) && in_array('lwt-4243', $guids, true),
    'guids survived, so terms can be matched back'
);

/** @var array{answered?: bool, guid?: string} $answer */
$answer = is_array($report['answer'] ?? null) ? $report['answer'] : [];
$check(($answer['answered'] ?? false) === true, 'a card was actually due to answer');
$check(
    ($answer['guid'] ?? '') === 'lwt-4242',
    'the card Anki offered is the studied one, as the seed intends'
);

// ---------------------------------------------------------------------------
// 2. LWT reads what Anki wrote back.
// ---------------------------------------------------------------------------

fwrite(STDOUT, "\nLWT reads what Anki wrote\n");

$readBack = (new ApkgReader())->read($legacyOut);
$byId = [];
foreach ($readBack as $note) {
    $byId[$note->lwtTermId] = $note;
}

$check(count($readBack) === 2, 'both notes read back (got ' . count($readBack) . ')');
$check(isset($byId[4242], $byId[4243]), 'both LWT term ids recovered');

$studied = $byId[4242] ?? null;
if ($studied !== null) {
    $check($studied->term === 'perro', 'the term text survived');
    $check($studied->translation === 'dog', 'the translation survived');
    $check($studied->tags === ['animal'], 'tags survived');
    $check($studied->schedule !== null, 'the studied note came back scheduled');
    if ($studied->schedule !== null) {
        $count = count($studied->schedule->reviews);
        $check(
            $count === 3,
            "history came back with the new review appended (expected 3, got {$count})"
        );
        $newest = null;
        foreach ($studied->schedule->reviews as $review) {
            if ($newest === null || $review->reviewedAt > $newest->reviewedAt) {
                $newest = $review;
            }
        }
        $check(
            $newest !== null && $newest->ease === 4,
            'the grade given in Anki came back as Easy'
        );
        $check(
            $newest !== null && $newest->reviewedAt > $reviewedAt,
            "the new review is newer than LWT's own last one, so the replay will apply it"
        );
    }
}

// ---------------------------------------------------------------------------
// 3. The format Anki writes by default.
// ---------------------------------------------------------------------------

fwrite(STDOUT, "\nAnki's default export format\n");

$modernOut = $workdir . '/modern.apkg';
[$code, , $stderr] = $runOracle([
    'roundtrip',
    '--in', $seedPath,
    '--out', $modernOut,
    '--report', $workdir . '/modern.json',
    '--modern',
]);
if ($check($code === 0, 'Anki exported in its default format' . ($code === 0 ? '' : ": {$stderr}"))) {
    $zip = new ZipArchive();
    $zip->open($modernOut);
    $hasModern = $zip->locateName('collection.anki21b') !== false;
    $zip->close();
    $check($hasModern, 'the default export really is the compressed format');

    if (function_exists('zstd_uncompress')) {
        $modernNotes = (new ApkgReader())->read($modernOut);
        $check(count($modernNotes) === 2, 'ext-zstd is installed, and the file reads');
        $modernIds = array_map(static fn(ApkgNote $n): int => $n->lwtTermId, $modernNotes);
        sort($modernIds);
        $check($modernIds === [4242, 4243], 'the compressed collection yields the same terms');
    } else {
        // The bug this whole file exists for: before #264 this read the stub
        // Anki leaves for old clients and called it a successful import.
        $refused = false;
        $message = '';
        try {
            (new ApkgReader())->read($modernOut);
        } catch (RuntimeException $e) {
            $refused = true;
            $message = $e->getMessage();
        }
        $check($refused, 'without ext-zstd the file is refused, not half-read');
        $check(
            str_contains($message, 'Support older Anki versions'),
            'the refusal names the Anki setting that fixes it'
        );
    }
}

// ---------------------------------------------------------------------------
// 4. A due date set by hand in Anki.
// ---------------------------------------------------------------------------

fwrite(STDOUT, "\nA due date set by hand in Anki\n");

$postponedOut = $workdir . '/postponed.apkg';
$postponedReport = $workdir . '/postponed.json';
[$code, , $stderr] = $runOracle([
    'roundtrip',
    '--in', $seedPath,
    '--out', $postponedOut,
    '--report', $postponedReport,
    '--set-due-date', '60',
]);
if ($check($code === 0, 'Anki set a due date by hand' . ($code === 0 ? '' : ": {$stderr}"))) {
    $postponed = $readReport($postponedReport);
    /** @var array{manual_revlog_rows?: int} $setDue */
    $setDue = is_array($postponed['set_due_date'] ?? null) ? $postponed['set_due_date'] : [];
    $check(
        ($setDue['manual_revlog_rows'] ?? 0) > 0,
        'Anki records it as a revlog row with no grade (ease 0, kind Manual)'
    );

    $postponedNotes = (new ApkgReader())->read($postponedOut);
    $studiedAgain = null;
    foreach ($postponedNotes as $note) {
        if ($note->lwtTermId === 4242) {
            $studiedAgain = $note;
        }
    }

    if ($check($studiedAgain?->schedule !== null, 'the postponed note came back scheduled')) {
        /** @var ApkgNote $studiedAgain */
        $schedule = $studiedAgain->schedule;
        $check(
            $schedule?->manualRescheduledAt !== null,
            'LWT sees that the date was set by hand'
        );
        // The whole point: without this the term would come back due on
        // whatever LWT last computed, and the two months the learner asked for
        // would silently vanish.
        $check(
            $schedule !== null && $schedule->due > new DateTimeImmutable('+50 days'),
            'the new due date is roughly the 60 days that were asked for'
        );
        $check(
            $schedule !== null && count($schedule->reviews) === 2,
            'the reschedule was not counted as a review (still 2 in the history)'
        );
    }
}

// ---------------------------------------------------------------------------
// 5. Anki's import default, which silently drops the schedule.
// ---------------------------------------------------------------------------

fwrite(STDOUT, "\nAnki's default import options\n");

$strippedOut = $workdir . '/stripped.apkg';
$strippedReport = $workdir . '/stripped.json';
[$code, , $stderr] = $runOracle([
    'roundtrip',
    '--in', $seedPath,
    '--out', $strippedOut,
    '--report', $strippedReport,
    '--no-import-scheduling',
]);
if ($check($code === 0, 'Anki imported without scheduling' . ($code === 0 ? '' : ": {$stderr}"))) {
    $stripped = $readReport($strippedReport);
    /** @var array{revlog_count?: int, note_count?: int} $state */
    $state = is_array($stripped['after_import'] ?? null) ? $stripped['after_import'] : [];
    // This is the assertion that justifies the warning on the import page. If
    // Anki ever changes this default, the notice should change with it, and
    // this is what would say so.
    $check(
        ($state['revlog_count'] ?? -1) === 0,
        'Anki still discards review history when "Import any learning progress" is off'
    );
    $check(($state['note_count'] ?? 0) === 2, 'the notes themselves still arrive');
}

// ---------------------------------------------------------------------------

array_map('unlink', glob($workdir . '/*') ?: []);
@rmdir($workdir);

if ($failures > 0) {
    fwrite(STDERR, "\n[FAIL] {$failures} check(s) failed against Anki {$version}\n");
    exit(1);
}

fwrite(STDOUT, "\n[OK] round trip clean against Anki {$version}\n");
exit(0);
