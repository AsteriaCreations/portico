<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
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
 * Starts another register's admission of $member on its own connection,
 * without waiting for it: it takes the same row lock as
 * CapacityService::lockForAdmission(), holds it for $seconds, then commits
 * the attendance row. Returns the connection so the caller can reap it.
 */
function admitOnAnotherRegister(Member $member, Event $event, int $seconds = 1): mysqli
{
    $config = DB::connection()->getConfig();
    $other = new mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);

    // One autocommit statement: the FOR UPDATE lock is taken as the row is
    // read and held until the statement (and so the insert) commits.
    $other->query(sprintf(
        'INSERT INTO attendance (member_id, event_id, checked_in_at, created_at, updated_at)
         SELECT %d, %d, NOW(), NOW(), NOW() FROM membership_settings WHERE SLEEP(%d) = 0 FOR UPDATE',
        $member->id,
        $event->id,
        $seconds,
    ), MYSQLI_ASYNC);

    // Long enough for that statement to reach the lock before this
    // connection goes for it.
    usleep(250_000);

    return $other;
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

    $other->reap_async_query();
    $other->close();

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

    $other->reap_async_query();
    $other->close();

    expect(Attendance::where('event_id', $event->id)->pluck('member_id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});
