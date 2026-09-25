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
