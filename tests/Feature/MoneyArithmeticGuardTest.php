<?php

use Illuminate\Support\Facades\File;

// Guards CONTRIBUTING.md's money rule (Architecture rule 7): services
// compute and compare money in int cents via App\Support\Cents, and floats
// are for display only. Both confirmed money bugs -- a balanced drawer
// reported "$0.00 over", and a card fee charged on a fully covered visit --
// came from float arithmetic on amounts the database stores exactly.

const MONEY_GUARD_ADVICE = 'Use App\Support\Cents: compute in int cents (Cents::of() to read, '
    .'Cents::toDecimal() to write) and convert with Cents::toFloat() only for display. '
    .'See CONTRIBUTING.md, Architecture rule 7. If a float really is safe here, add an '
    .'allowlist entry in this test saying why.';

/**
 * Floats a service may legitimately hold, keyed "path:token", where token
 * is "$name" for a parameter or property, "name()" for a return type, or
 * "(float)" for a cast anywhere in that file. Every entry says why.
 *
 * @return array<string, string>
 */
function moneyGuardAllowlist(): array
{
    return [
        // The desk's drop and miscellaneous-payment forms hand over a
        // numeric field already cast to float; the service converts it with
        // Cents::toDecimal(Cents::of()) before it touches the database and
        // does no arithmetic on it.
        'app/Services/RegisterShiftService.php:$amount' => 'form input, converted with Cents on write',
        // Display wrapper: sums in cents (totalDropsCents()) and converts
        // with Cents::toFloat() for the Register Shifts table's money
        // column. Reconciliation compares the cents method, never this.
        'app/Services/RegisterShiftService.php:totalDrops()' => 'display only, converted from cents',
    ];
}

/**
 * Float uses in one PHP source file that look like money: every (float)
 * cast, plus a float parameter, property or return type whose name
 * suggests an amount. Comment lines are skipped.
 *
 * @return array<int, array{token: string, line: int, source: string}>
 */
function moneyFloatUsesIn(string $contents): array
{
    $moneyName = '/amount|price|fee|total|balance|coverage|credit|revenue|payout|rate/i';
    $lines = explode("\n", $contents);
    $uses = [];

    $record = function (string $token, int $offset) use ($contents, $lines, &$uses): void {
        $number = substr_count($contents, "\n", 0, $offset);

        if (preg_match('#^\s*(//|\*|/\*)#', $lines[$number])) {
            return;
        }

        $uses[] = ['token' => $token, 'line' => $number + 1, 'source' => trim($lines[$number])];
    };

    preg_match_all('/\(\s*float\s*\)/', $contents, $casts, PREG_OFFSET_CAPTURE);
    foreach ($casts[0] as [, $offset]) {
        $record('(float)', $offset);
    }

    // A typed parameter or property: "float $x", "?float $x", "int|float $x".
    preg_match_all('/[\w|?\\\\]*\bfloat\b[\w|?\\\\]*\s+(?:\.\.\.)?\$(\w+)/', $contents, $typed, PREG_OFFSET_CAPTURE);
    foreach ($typed[1] as [$name, $offset]) {
        if (preg_match($moneyName, $name)) {
            $record('$'.$name, $offset);
        }
    }

    preg_match_all('/function\s+(\w+)\s*\([^)]*\)\s*:\s*[\w|?\\\\]*\bfloat\b/', $contents, $returns, PREG_OFFSET_CAPTURE);
    foreach ($returns[1] as [$name, $offset]) {
        if (preg_match($moneyName, $name)) {
            $record($name.'()', $offset);
        }
    }

    return $uses;
}

/**
 * Every money-looking float use under app/Services and app/Support (bar
 * Cents itself, the one place allowed to convert), as "path:token" =>
 * "path:line  source".
 *
 * @return array<string, string>
 */
function moneyFloatUsesInApp(): array
{
    $basePath = str_replace('\\', '/', base_path()).'/';
    $found = [];

    foreach (['Services', 'Support'] as $directory) {
        foreach (File::allFiles(app_path($directory)) as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($basePath));

            if ($file->getExtension() !== 'php' || $relative === 'app/Support/Cents.php') {
                continue;
            }

            foreach (moneyFloatUsesIn(file_get_contents($file->getPathname())) as $use) {
                $found[$relative.':'.$use['token']][] = $relative.':'.$use['line'].'  '.$use['source'];
            }
        }
    }

    return $found;
}

test('no service does money arithmetic in floats', function () {
    $offenders = collect(moneyFloatUsesInApp())
        ->reject(fn (array $uses, string $key): bool => array_key_exists($key, moneyGuardAllowlist()))
        ->flatten()
        ->values()
        ->all();

    expect($offenders)->toBe([], MONEY_GUARD_ADVICE);
});

test('every allowlist entry still matches a float use, so none outlives its reason', function () {
    $stale = array_values(array_diff(array_keys(moneyGuardAllowlist()), array_keys(moneyFloatUsesInApp())));

    expect($stale)->toBe([]);
});

test('the scanner catches a float cast, a money-named float parameter and a money-named float return', function () {
    $source = <<<'PHP'
        <?php
        class Planted
        {
            public function payoutFor(Event $event, ?float $rate): float
            {
                // (float) in a comment is fine
                return (float) $event->price * $rate;
            }

            public function openFor(float $openingCount): void {}
        }
        PHP;

    expect(collect(moneyFloatUsesIn($source))->pluck('token')->sort()->values()->all())
        ->toBe(['$rate', '(float)', 'payoutFor()']);
});
