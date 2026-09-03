<?php

use App\Enums\MemberStatusField;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\AttendanceAddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MemberStatusChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('banning a member writes an audit row capturing who and why', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $member = Member::factory()->create(['is_banned' => false]);

    $member->update(['is_banned' => true, 'ban_reason' => 'Fought at the bar']);

    $change = MemberStatusChange::where('member_id', $member->id)->firstOrFail();
    expect($change->status)->toBe(MemberStatusField::Banned)
        ->and($change->value)->toBeTrue()
        ->and($change->reason)->toBe('Fought at the bar')
        ->and($change->changed_by)->toBe($manager->id);
});

test('watchlisting a member writes an audit row capturing who and why', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);
    $member = Member::factory()->create(['on_watchlist' => false]);

    $member->update(['on_watchlist' => true, 'watchlist_reason' => 'Prior incident']);

    $change = MemberStatusChange::where('member_id', $member->id)->firstOrFail();
    expect($change->status)->toBe(MemberStatusField::Watchlist)
        ->and($change->value)->toBeTrue()
        ->and($change->reason)->toBe('Prior incident')
        ->and($change->changed_by)->toBe($admin->id);
});

test('marking a member deceased writes an audit row', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $member = Member::factory()->create(['is_deceased' => false]);

    $member->update(['is_deceased' => true]);

    $change = MemberStatusChange::where('member_id', $member->id)->firstOrFail();
    expect($change->status)->toBe(MemberStatusField::Deceased)
        ->and($change->value)->toBeTrue()
        ->and($change->changed_by)->toBe($manager->id);
});

test('toggling missing_paperwork writes an audit row', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $member = Member::factory()->create(['missing_paperwork' => false]);

    $member->update(['missing_paperwork' => true]);

    $change = MemberStatusChange::where('member_id', $member->id)->firstOrFail();
    expect($change->status)->toBe(MemberStatusField::MissingPaperwork)
        ->and($change->value)->toBeTrue()
        ->and($change->changed_by)->toBe($manager->id);
});

test('toggling is_active writes an audit row', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $member = Member::factory()->create(['is_active' => true]);

    $member->update(['is_active' => false]);

    $change = MemberStatusChange::where('member_id', $member->id)->firstOrFail();
    expect($change->status)->toBe(MemberStatusField::Active)
        ->and($change->value)->toBeFalse()
        ->and($change->changed_by)->toBe($manager->id);
});

test('a console-driven deceased/paperwork/active change writes no audit row', function () {
    $member = Member::factory()->create(['is_deceased' => false, 'missing_paperwork' => false, 'is_active' => true]);

    $member->update(['is_deceased' => true, 'missing_paperwork' => true, 'is_active' => false]);

    expect(MemberStatusChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('editing an unrelated field writes no audit row', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $member = Member::factory()->create();

    $member->update(['first_name' => 'Changed']);

    expect(MemberStatusChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('a console-driven change with no authenticated user writes no audit row', function () {
    $member = Member::factory()->create(['is_banned' => false]);

    $member->update(['is_banned' => true]);

    expect(MemberStatusChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('a member status change can never be updated or deleted, even by an admin', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $change = MemberStatusChange::factory()->create();

    expect($admin->can('update', $change))->toBeFalse()
        ->and($admin->can('delete', $change))->toBeFalse();
});

test('banning a guest checked in tonight appends a note to the sponsor', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now()]);

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'Fought at the bar']);

    $sponsor->refresh();
    expect($sponsor->notes)->toContain($guest->username)
        ->and($sponsor->notes)->toContain('banned')
        ->and($sponsor->notes)->toContain('Fought at the bar');
});

test('watchlisting a guest checked in tonight appends a note to the sponsor', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'on_watchlist' => false]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now()]);

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['on_watchlist' => true, 'watchlist_reason' => 'Rowdy']);

    $sponsor->refresh();
    expect($sponsor->notes)->toContain('put on the watchlist');
});

test('banning a guest with no attendance tonight leaves the sponsor untouched', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'Unrelated to any visit']);

    $sponsor->refresh();
    expect($sponsor->notes)->toBeNull();
});

test('banning a non-guest member never touches any sponsor field', function () {
    $sponsor = Member::factory()->create(['notes' => null]);
    $member = Member::factory()->create(['sponsor_id' => $sponsor->id, 'is_banned' => false]);
    $event = Event::factory()->create();
    $member->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now()]);

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $member->update(['is_banned' => true]);

    $sponsor->refresh();
    expect($sponsor->notes)->toBeNull();
});

test('lifting a ban does not write a sponsor note', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => true, 'ban_reason' => 'x']);
    $event = Event::factory()->create();
    $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now()]);

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => false]);

    $sponsor->refresh();
    expect($sponsor->notes)->toBeNull();
});

test('a guest checked in just before midnight still notifies the sponsor once the clock rolls over, via the event\'s own active window', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);
    $event = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 22:00:00',
        'ends_at' => '2026-07-19 02:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-18 23:45:00'));
    $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now()]);

    // today() has now rolled over to the 19th -- the old whereDate(checked_in_at,
    // today()) check would have missed this guest entirely.
    $this->travelTo(Carbon::parse('2026-07-19 00:15:00'));

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'Fought at the bar']);

    $sponsor->refresh();
    expect($sponsor->notes)->toContain($guest->username);
});

test('a non-overnight guest whose event window has fully closed no longer notifies the sponsor', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);
    $event = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 20:00:00',
        'ends_at' => '2026-07-18 23:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-18 21:00:00'));
    $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now()]);

    // Well past ends_at plus the default buffer, and event_date no longer
    // matches today() either.
    $this->travelTo(Carbon::parse('2026-07-19 12:00:00'));

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'Unrelated']);

    $sponsor->refresh();
    expect($sponsor->notes)->toBeNull();
});

test('an overnight-flagged guest still notifies the sponsor days after their event ended, as long as they have not been checked out', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);
    $event = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 20:00:00',
        'ends_at' => '2026-07-18 23:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-18 21:00:00'));
    $attendance = $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now(), 'departed_at' => null]);
    $addOn = AddOn::factory()->create(['name' => 'Sleepover', 'is_overnight' => true]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => true,
    ]);

    $this->travelTo(Carbon::parse('2026-07-20 15:00:00'));

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'Trouble the next morning']);

    $sponsor->refresh();
    expect($sponsor->notes)->toContain($guest->username);
});

test('an overnight-flagged guest who has already been checked out no longer notifies the sponsor', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);
    $event = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 20:00:00',
        'ends_at' => '2026-07-18 23:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-18 21:00:00'));
    $attendance = $guest->attendance()->create(['event_id' => $event->id, 'checked_in_at' => now(), 'departed_at' => now()->addDay()]);
    $addOn = AddOn::factory()->create(['name' => 'Sleepover', 'is_overnight' => true]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => true,
    ]);

    $this->travelTo(Carbon::parse('2026-07-20 15:00:00'));

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'Long gone']);

    $sponsor->refresh();
    expect($sponsor->notes)->toBeNull();
});

test('a guest with an old closed-window visit and a separate current overnight visit still notifies the sponsor via the qualifying row', function () {
    $guestCategory = Category::factory()->create(['name' => 'Guest']);
    $sponsor = Member::factory()->create(['notes' => null]);
    $guest = Member::factory()->create(['category_id' => $guestCategory->id, 'sponsor_id' => $sponsor->id, 'is_banned' => false]);

    $oldEvent = Event::factory()->create([
        'event_date' => '2026-06-01',
        'starts_at' => '2026-06-01 20:00:00',
        'ends_at' => '2026-06-01 23:00:00',
    ]);
    $guest->attendance()->create(['event_id' => $oldEvent->id, 'checked_in_at' => Carbon::parse('2026-06-01 21:00:00')]);

    $overnightEvent = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 20:00:00',
        'ends_at' => '2026-07-18 23:00:00',
    ]);
    $attendance = $guest->attendance()->create(['event_id' => $overnightEvent->id, 'checked_in_at' => Carbon::parse('2026-07-18 21:00:00'), 'departed_at' => null]);
    $addOn = AddOn::factory()->create(['name' => 'Private room rental', 'is_overnight' => true]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => true,
    ]);

    $this->travelTo(Carbon::parse('2026-07-20 15:00:00'));

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $guest->update(['is_banned' => true, 'ban_reason' => 'x']);

    $sponsor->refresh();
    expect($sponsor->notes)->toContain($guest->username);
});
