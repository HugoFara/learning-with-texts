<?php

/**
 * Move terms' last status change into the past, for E2E specs.
 *
 * Usage: php cypress/support/backdate-terms.php <days> <term id> [<term id>...]
 *
 * The review queue only offers terms that are due, and a term created during a
 * spec falls due a day later at the earliest. Backdating WoStatusChanged makes
 * it due exactly as if it had been learned <days> ago; nothing else about the
 * term changes. Runs against the database in .env, the one the dev server uses.
 *
 * PHP version 8.2
 *
 * @category Tests
 * @package  Lwt\Tests\E2E
 * @license  Unlicense <http://unlicense.org/>
 */

declare(strict_types=1);

use Lwt\Shared\Infrastructure\Database\Configuration;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$days = (int) ($argv[1] ?? 0);
$ids = array_values(array_filter(array_map('intval', array_slice($argv, 2))));
if ($days <= 0 || $ids === []) {
    fwrite(STDERR, "Usage: php backdate-terms.php <days> <term id> [<term id>...]\n");
    exit(2);
}

$config = Configuration::loadFromEnv(dirname(__DIR__, 2) . '/.env');
$db = Configuration::connect(
    $config['server'],
    $config['userid'],
    $config['passwd'],
    $config['dbname'],
    $config['socket']
);

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$statement = $db->prepare(
    "UPDATE words SET WoStatusChanged = NOW() - INTERVAL ? DAY WHERE WoID IN ($placeholders)"
);
if ($statement === false) {
    fwrite(STDERR, $db->error . "\n");
    exit(1);
}
$statement->bind_param(str_repeat('i', count($ids) + 1), $days, ...$ids);
$statement->execute();
echo $statement->affected_rows . "\n";
