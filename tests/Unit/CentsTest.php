<?php

use App\Support\Cents;

test('reads decimal strings, floats, ints and null as whole cents', function (string|int|float|null $amount, int $cents) {
    expect(Cents::of($amount))->toBe($cents);
})->with([
    'decimal cast string' => ['30.05', 3005],
    'negative string' => ['-20.10', -2010],
    'SQL SUM string' => ['123456.78', 12345678],
    'float just under a cent boundary' => [30.05, 3005],
    'float sum with error' => [0.1 + 0.2, 30],
    'int dollars' => [20, 2000],
    'null' => [null, 0],
]);

test('stays exact for any two-decimal amount up to a decimal(8,2) column\'s range', function () {
    foreach ([1, 5, 10, 29, 99, 305, 1005, 3005, 99999, 999999, 99999999] as $cents) {
        expect(Cents::of(number_format($cents / 100, 2, '.', '')))->toBe($cents)
            ->and(Cents::of($cents / 100))->toBe($cents)
            ->and(Cents::of(-$cents / 100))->toBe(-$cents);
    }
});

test('converts back to a float that is exactly zero when the cents are', function () {
    expect(Cents::toFloat(0))->toBe(0.0)
        ->and(Cents::toFloat(3005))->toBe(30.05)
        ->and(Cents::toFloat(-5))->toBe(-0.05);
});

test('writes cents as a two-decimal string for a decimal(8,2) column', function (int $cents, string $decimal) {
    expect(Cents::toDecimal($cents))->toBe($decimal);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [30, '0.30'],
    [3005, '30.05'],
    [-30, '-0.30'],
    [-2010, '-20.10'],
    [99999999, '999999.99'],
]);

test('takes a percentage rounded once to the cent, half-up', function (int $cents, string $percent, int $expected) {
    expect(Cents::percentOf($cents, $percent))->toBe($expected);
})->with([
    'exact' => [101000, '15.00', 15150],
    'fraction of a cent rounds down' => [10010, '33.33', 3336], // 3336.333
    'exact half cent rounds up, not to even' => [1030, '15.00', 155], // 154.5
    'just under half a cent' => [1003, '15.00', 150], // 150.45
    'negative half cent rounds away from zero' => [-1030, '15.00', -155],
    'zero door' => [0, '10.00', 0],
]);
