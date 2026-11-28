<?php

/**
 * Writes phpunit.shard.xml: phpunit.xml with its test suites replaced by
 * every Nth test file (sorted by path), for one CI shard.
 *
 * Usage: php scripts/ci-shard-phpunit.php <shard> <total>
 *
 * Paratest (`artisan test --parallel`) takes only one path argument, and
 * Pest's own --shard misbehaves combined with --parallel, so CI shards by
 * pointing each runner at its own generated configuration instead.
 */
[, $shard, $total] = $argv + [null, null, null];

if (! ctype_digit((string) $shard) || ! ctype_digit((string) $total) || $shard < 1 || $shard > $total) {
    fwrite(STDERR, "Usage: php scripts/ci-shard-phpunit.php <shard> <total>\n");
    exit(2);
}

$root = dirname(__DIR__);
$files = [];

foreach (['tests/Unit', 'tests/Feature'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

sort($files);

$mine = array_values(array_filter($files, fn (string $file, int $index): bool => $index % $total === $shard - 1, ARRAY_FILTER_USE_BOTH));

$entries = implode('', array_map(fn (string $file): string => "\n            <file>{$file}</file>", $mine));
$config = preg_replace(
    '#<testsuites>.*?</testsuites>#s',
    "<testsuites>\n        <testsuite name=\"Shard {$shard} of {$total}\">{$entries}\n        </testsuite>\n    </testsuites>",
    file_get_contents("{$root}/phpunit.xml"),
    1,
);

file_put_contents("{$root}/phpunit.shard.xml", $config);

echo 'Shard '.$shard.' of '.$total.': '.count($mine).' of '.count($files)." test files.\n";
