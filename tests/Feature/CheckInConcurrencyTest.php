<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Real two-connection races, which only MariaDB/MySQL can stage: SQLite has
 * no row locks, so lockForUpdate() is a no-op there and every test below
 * skips. CI's MariaDB job runs the whole suite, these included.
 *
 * No RefreshDatabase: the second connection has to see this test's rows,
 * so they must actually be committed. Truncating afterwards isn't enough
 * either -- it would also wipe rows the migrations themselves seed (payment
 * methods, paperwork types) that later tests rely on -- so the schema is
 * rebuilt instead, leaving the same state a RefreshDatabase test starts from.
 */
function usesRowLocks(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
}

beforeEach(function () {
    if (! usesRowLocks()) {
        $this->markTestSkipped('Needs MariaDB/MySQL row locks; run the suite with DB_CONNECTION=mariadb.');
    }

    if (! RefreshDatabaseState::$migrated) {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    }

    $this->category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

afterEach(function () {
    if (usesRowLocks()) {
        $this->artisan('migrate:fresh');
    }
});

/**
 * Runs `INSERT INTO $table ($columns) SELECT $values` on its own connection,
 * as another register's in-flight sale, without waiting for it: the
 * statement takes the same row lock as CapacityService::lockForAdmission(),
 * holds it for $seconds, then commits the row. Returns the connection so the
 * caller can reap it.
 */
function sellOnAnotherRegister(string $table, string $columns, string $values, int $seconds = 1): mysqli
{
    $config = DB::connection()->getConfig();
    $other = new mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);

    // One autocommit statement: the FOR UPDATE lock is taken as the row is
    // read and held until the statement (and so the insert) commits.
    $other->query(
        "INSERT INTO {$table} ({$columns}) SELECT {$values} FROM membership_settings WHERE SLEEP({$seconds}) = 0 FOR UPDATE",
        MYSQLI_ASYNC,
    );

    // Long enough for that statement to reach the lock before this
    // connection goes for it.
    usleep(250_000);

    return $other;
}

/**
 * Waits for sellOnAnotherRegister()'s statement and fails the test if it
 * didn't insert: a statement that errors out releases the lock at once,
 * and the race under test would then never have happened.
 */
function finishSaleOnAnotherRegister(mysqli $other): void
{
    $result = $other->reap_async_query();
    $error = $other->error;
    $other->close();

    expect($result)->not->toBeFalse("The other register's insert failed: {$error}");
}

function admitOnAnotherRegister(Member $member, Event $event): mysqli
{
    return sellOnAnotherRegister(
        'attendance',
        'member_id, event_id, checked_in_at, created_at, updated_at',
        sprintf('%d, %d, NOW(), NOW(), NOW()', $member->id, $event->id),
    );
}

function raceMember(Category $category, string $username): Member
{
    return Member::factory()->create([
        'category_id' => $category->id,
        'username' => $username,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
    ]);
}

test('two registers admitting different members for the last spot admit only one', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20]);
    $first = raceMember($this->category, 'first-register');
    $second = raceMember($this->category, 'second-register');

    // Mounted while the building still has room, as it would be on a
    // register a moment before the other one submits.
    $livewire = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $second->id])
        ->mountAction('checkIn');

    $other = admitOnAnotherRegister($first, $event);

    // Without the admission lock this counted occupancy while the other
    // register's row was still uncommitted, saw 0 of 1, and admitted too.
    $livewire->setActionData(['checked_in_at' => now()])
        ->callMountedAction()
        ->assertNotified(__('Building at capacity — check-in not saved.'));

    finishSaleOnAnotherRegister($other);

    expect(Attendance::where('event_id', $event->id)->pluck('member_id')->all())->toBe([$first->id]);
});

test('with room to spare, a register waits out another admission instead of failing', function () {
    MembershipSetting::current()->update(['venue_capacity' => 2]);
    $event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20]);
    $first = raceMember($this->category, 'first-register');
    $second = raceMember($this->category, 'second-register');

    $livewire = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $second->id])
        ->mountAction('checkIn');

    $other = admitOnAnotherRegister($first, $event);

    $livewire->setActionData(['checked_in_at' => now()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    finishSaleOnAnotherRegister($other);

    expect(Attendance::where('event_id', $event->id)->pluck('member_id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

test('two registers selling the last unit of a capped add-on sell it only once', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $room = AddOn::factory()->create(['name' => 'Private room rental', 'price' => 50, 'max_per_night' => 1]);
    $event->addOns()->attach($room->id);
    $first = raceMember($this->category, 'first-register');
    $second = raceMember($this->category, 'second-register');
    $firstAttendance = Attendance::factory()->for($first)->for($event)->create(['checked_in_at' => now()]);

    // Mounted with the room still offered, as on a register a moment
    // before the other one sells it.
    $livewire = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $second->id])
        ->fillForm(['add_on_ids' => [$room->id]], 'pricingForm')
        ->mountAction('checkIn');

    $other = sellOnAnotherRegister(
        'attendance_add_ons',
        'attendance_id, add_on_id, name, price, created_at',
        sprintf("%d, %d, 'Private room rental', 50, NOW()", $firstAttendance->id, $room->id),
    );

    // Without the re-check this counted the room's sales while the other
    // register's row was still uncommitted, saw 0 of 1, and sold it too.
    $livewire->setActionData(['checked_in_at' => now()])
        ->callMountedAction()
        ->assertNotified(__(':add_on sold out for tonight — check-in not saved.', ['add_on' => 'Private room rental']));

    finishSaleOnAnotherRegister($other);

    expect(AttendanceAddOn::where('add_on_id', $room->id)->pluck('attendance_id')->all())->toBe([$firstAttendance->id])
        ->and(Attendance::where('member_id', $second->id)->exists())->toBeFalse();
});
