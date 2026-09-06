<?php

use App\Enums\PayoutType;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\Category;
use App\Models\CompReason;
use App\Models\EventType;
use App\Models\InstructorPayRate;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

afterEach(function () {
    putenv('ADMIN_EMAIL');
    putenv('ADMIN_PASSWORD');
    unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_EMAIL'], $_SERVER['ADMIN_PASSWORD']);
});

function forceProductionEnv(): void
{
    app()['env'] = 'production';
}

function setAdminEnv(string $email, string $password): void
{
    foreach (['ADMIN_EMAIL' => $email, 'ADMIN_PASSWORD' => $password] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

test('categories are seeded with the correct comp flags', function () {
    $this->seed();

    expect(Category::count())->toBe(9);

    expect(Category::where('is_comped', true)->pluck('name')->sort()->values()->all())
        ->toBe(['Emeritus', 'Manager', 'Owner', 'Staff']);
});

test('no comp reasons are seeded by default', function () {
    $this->seed();

    // comp_reasons is editable settings data the club fills in itself --
    // nothing ships as a default. See docs/BLUEPRINT.md "Per-event comp".
    expect(CompReason::count())->toBe(0);
});

test('event types are seeded', function () {
    $this->seed();

    expect(EventType::count())->toBe(8);
    expect(EventType::orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Social', 'Pool Social', 'Class', 'Munch', 'Private Rental', 'Meeting', 'Special', 'Yoga']);
});

test('plans are seeded with the starting fees', function () {
    $this->seed();

    $regular = Plan::where('add_on_id', AddOn::entry()->id)->firstOrFail();
    expect($regular->price)->toEqual(60.00);
    expect($regular->credit)->toEqual(25.00);

    $pool = Plan::where('add_on_id', AddOn::pool()->id)->firstOrFail();
    expect($pool->price)->toEqual(15.00);
    expect($pool->credit)->toBeNull();
});

test('showrunner payout tiers and Yoga instructor pay rates are seeded', function () {
    $this->seed();

    expect(ShowrunnerPayoutTier::count())->toBe(3);

    $topTier = ShowrunnerPayoutTier::where('min_headcount', 101)->firstOrFail();
    expect($topTier->max_headcount)->toBeNull()
        ->and($topTier->payout_type)->toBe(PayoutType::Percentage);

    $yoga = EventType::where('name', 'Yoga')->firstOrFail();
    expect(InstructorPayRate::where('event_type_id', $yoga->id)->count())->toBe(2);
});

test('outside local it seeds the admin from env credentials without the factory', function () {
    forceProductionEnv();
    setAdminEnv('owner@example.test', 'a-real-password');

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    $admin = User::where('email', 'owner@example.test')->sole();
    expect($admin->role)->toBe(Role::Admin)
        ->and($admin->active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-real-password', $admin->password))->toBeTrue();

    // the guessable dev admin must never exist in a non-local seed
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('outside local it refuses to seed without admin credentials', function () {
    forceProductionEnv();

    $this->app->make(DatabaseSeeder::class)->run();
})->throws(RuntimeException::class, 'Set ADMIN_EMAIL and ADMIN_PASSWORD');
