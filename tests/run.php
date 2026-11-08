<?php
declare(strict_types=1);

/**
 * Dependency-free test runner with line coverage via Xdebug.
 *
 *   php -d xdebug.mode=coverage tests/run.php
 *
 * Exits 0 only when every test passes and every executable line in the
 * runtime code is covered.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$appDir = realpath(__DIR__ . '/../app');

if ($appDir === false) {
    fwrite(STDERR, "Cannot locate app/\n");
    exit(2);
}

if (!function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Xdebug is required. Install it or run: php -d zend_extension=xdebug tests/run.php\n");
    exit(2);
}

$mode = xdebug_info('mode');

if (!is_array($mode) || !in_array('coverage', $mode, true)) {
    fwrite(STDERR, "Xdebug coverage is off. Run: php -d xdebug.mode=coverage tests/run.php\n");
    exit(2);
}

require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/workspace.php';

xdebug_start_code_coverage(XDEBUG_CC_UNUSED);

require $appDir . '/src/bootstrap.php';

// The front controllers must render from scratch, not from a previous run's cache.
Bio\Cache::flush($appDir . '/cache');

$started = microtime(true);
$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases);

foreach ($cases as $case) {
    require $case;
}

$coverage = xdebug_get_code_coverage();
xdebug_stop_code_coverage();

// ---------------------------------------------------------------------------
// Coverage report
// ---------------------------------------------------------------------------
$byFile = [];

foreach ($coverage as $file => $lines) {
    $real = realpath((string) $file);

    if ($real !== false) {
        $byFile[$real] = $lines;
    }
}

$targets = array_merge(
    glob($appDir . '/src/*.php') ?: [],
    [$appDir . '/index.php', $appDir . '/mcp.php', $appDir . '/templates/page.php']
);

$totalExecutable = 0;
$totalCovered = 0;
$gaps = [];

echo "\n== Coverage ==\n";

foreach ($targets as $target) {
    $real = realpath($target);

    if ($real === false) {
        continue;
    }

    $source = file($real) ?: [];
    $lines = $byFile[$real] ?? null;
    $name = str_replace(dirname($appDir) . DIRECTORY_SEPARATOR, '', $real);

    if ($lines === null) {
        printf("  %s %-38s %5.1f%%  (no coverage data)\n", 'GAP ', $name, 0.0);
        $gaps[$name] = ['no coverage data'];

        continue;
    }

    $executable = 0;
    $covered = 0;
    $uncovered = [];

    foreach ($lines as $line => $hits) {
        if ($hits === -1) {
            continue; // dead code reported by Xdebug
        }

        $text = $source[$line - 1] ?? '';

        if (str_contains($text, '@covIgnore')) {
            continue;
        }

        $executable++;

        if ($hits > 0) {
            $covered++;
        } else {
            $uncovered[] = $line;
        }
    }

    $totalExecutable += $executable;
    $totalCovered += $covered;
    $percent = $executable === 0 ? 100.0 : round($covered / $executable * 100, 1);
    $flag = $uncovered === [] ? 'ok  ' : 'GAP ';
    printf("  %s %-38s %5.1f%%  (%d/%d)\n", $flag, $name, $percent, $covered, $executable);

    if ($uncovered !== []) {
        $gaps[$name] = $uncovered;
    }
}

$totalPercent = $totalExecutable === 0 ? 100.0 : round($totalCovered / $totalExecutable * 100, 2);
printf("  %-42s %5.2f%%  (%d/%d)\n", 'TOTAL', $totalPercent, $totalCovered, $totalExecutable);

foreach ($gaps as $name => $lines) {
    echo "\n  Uncovered lines in {$name}: " . implode(', ', $lines) . "\n";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$elapsed = round(microtime(true) - $started, 2);

echo "\n" . str_repeat('=', 60) . "\n";
echo "Tests: " . Harness::$passed . " passed, " . Harness::$failed . " failed";
echo "  |  Coverage: {$totalPercent}%  |  {$elapsed}s\n";

if (Harness::$failed > 0) {
    echo "\nFailures:\n";

    foreach (Harness::$failures as [$name, $error]) {
        echo " - {$name}: {$error->getMessage()}\n";
    }
}

$ok = Harness::$failed === 0 && $gaps === [];

echo $ok ? "\nOK\n" : "\nFAILED\n";
exit($ok ? 0 : 1);
