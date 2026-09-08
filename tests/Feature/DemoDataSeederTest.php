<?php

use App\Models\CompRequest;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it populates the core demo tables, including comp requests', function () {
    $this->seed();
    $this->seed(DemoDataSeeder::class);

    expect(Member::count())->toBeGreaterThan(50)
        ->and(User::where('email', 'like', 'demo-%')->count())->toBeGreaterThan(0)
        // comp requests only seed when comp_reasons exist -- guards against
        // regressing the starter CompReasonSeeder.
        ->and(CompRequest::count())->toBeGreaterThan(0);
});

test('re-running it on an already-seeded database is a no-op, not a crash', function () {
    $this->seed();
    $this->seed(DemoDataSeeder::class);

    $before = User::count();
    $this->seed(DemoDataSeeder::class); // must not throw

    expect(User::count())->toBe($before);
});
