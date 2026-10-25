<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Signing in with a login linked to a member checks that member in when
 * they owe nothing (StaffLoginCheckInService), behind
 * staff_login_checkin_enabled.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['staff_login_checkin_enabled' => true]);

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    $this->category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);

    $this->event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $this->member = staffLoginMember($this->category);
    $this->user = User::factory()->create(['role' => Role::Volunteer, 'active' => true, 'member_id' => $this->member->id]);

    $this->desk = User::factory()->create(['role' => Role::Door, 'active' => true]);
    staffLoginDeskSession($this->desk);
});

function staffLoginMember(Category $category, array $overrides = []): Member
{
    return Member::factory()->create([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'missing_paperwork' => false,
        ...$overrides,
    ]);
}

function staffLoginSubscribe(Member $member, AddOn $entry): void
{
    Subscription::factory()->create([
        'member_id' => $member->id,
        'add_on_id' => $entry->id,
        'covered_month' => now()->startOfMonth()->toDateString(),
    ]);
}

function staffLoginDeskSession(User $user): void
{
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

function staffLoginVisit(Member $member): ?Attendance
{
    return Attendance::where('member_id', $member->id)->first();
}

test('signing in through the login page checks a subscriber in with nothing due', function () {
    staffLoginSubscribe($this->member, $this->entry);
    $this->user->update(['password' => 'Correct-horse-1!']);

    Livewire::test(Login::class)
        ->set('data.email', $this->user->email)
        ->set('data.password', 'Correct-horse-1!')
        ->call('authenticate')
        ->assertHasNoErrors();

    $visit = staffLoginVisit($this->member);
    expect($visit)->not->toBeNull()
        ->and($visit->event_id)->toBe($this->event->id)
        ->and($visit->entry_covered_by)->toBe(EntryCoverageSource::RegularSubscription)
        ->and($visit->amount_paid)->toEqual(0)
        ->and($visit->payment_method)->toBeNull()
        ->and($visit->checked_in_at)->not->toBeNull()
        ->and($visit->checked_in_by)->toBe($this->user->id)
        ->and($this->desk->notifications()->count())->toBe(0);
});

test('any $0 visit counts, such as a comped category', function () {
    $staff = Category::factory()->create(['name' => 'Staff', 'is_comped' => true]);
    $this->member->update(['category_id' => $staff->id]);

    Auth::login($this->user);

    expect(staffLoginVisit($this->member)?->amount_paid)->toEqual(0);
});

test('nothing happens while the flag is off', function () {
    MembershipSetting::current()->update(['staff_login_checkin_enabled' => false]);
    staffLoginSubscribe($this->member, $this->entry);

    Auth::login($this->user);

    expect(staffLoginVisit($this->member))->toBeNull()
        ->and($this->user->notifications()->count())->toBe(0);
});

test('a login with no linked member, or signing in with no event running, does nothing', function () {
    $unlinked = User::factory()->create(['role' => Role::Door, 'active' => true]);
    Auth::login($unlinked);

    $this->event->update(['event_date' => today()->subWeek()->toDateString(), 'starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHours(4)]);
    staffLoginSubscribe($this->member, $this->entry);
    Auth::login($this->user);

    expect(Attendance::count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(0)
        ->and($this->desk->notifications()->count())->toBe(0);
});

test('money due sends the person to the desk and alerts the Door staff signed in', function () {
    $offline = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $volunteerOnline = User::factory()->create(['role' => Role::Volunteer, 'active' => true]);
    staffLoginDeskSession($volunteerOnline);

    Auth::login($this->user);

    expect(staffLoginVisit($this->member))->toBeNull()
        ->and($this->user->notifications()->first()->data['title'])->toContain('$20.00')->toContain($this->event->name)
        ->and($this->desk->notifications()->count())->toBe(1)
        ->and($this->desk->notifications()->first()->data['title'])->toContain($this->member->displayName())
        ->and($offline->notifications()->count())->toBe(0)
        ->and($volunteerOnline->notifications()->count())->toBe(0);
});

test('a watchlist member is sent to the desk without being told why', function () {
    staffLoginSubscribe($this->member, $this->entry);
    $this->member->update(['on_watchlist' => true, 'watchlist_reason' => 'secret reason']);

    Auth::login($this->user);

    $toUser = $this->user->notifications()->first()->data;
    expect(staffLoginVisit($this->member))->toBeNull()
        ->and($toUser['title'].' '.$toUser['body'])->not->toContain('atchlist')->not->toContain('secret reason')
        ->and($this->desk->notifications()->count())->toBe(1);
});

test('two events running at once sends them to the desk', function () {
    staffLoginSubscribe($this->member, $this->entry);
    Event::factory()->create(['event_date' => today()->toDateString(), 'starts_at' => $this->event->starts_at, 'ends_at' => $this->event->ends_at, 'entry_fee' => 20, 'pool_fee' => 0]);

    Auth::login($this->user);

    expect(staffLoginVisit($this->member))->toBeNull()
        ->and($this->desk->notifications()->count())->toBe(1);
});

test('check-in closed sends them to the desk', function () {
    staffLoginSubscribe($this->member, $this->entry);
    MembershipSetting::current()->update(['first_visit_after_close_enabled' => false]);
    $this->event->update(['checkin_closes_at' => now()->subMinute()]);

    Auth::login($this->user);

    expect(staffLoginVisit($this->member))->toBeNull()
        ->and($this->desk->notifications()->count())->toBe(1);
});

test('signing in again after being checked in changes nothing', function () {
    staffLoginSubscribe($this->member, $this->entry);

    Auth::login($this->user);
    Auth::logout();
    Auth::login($this->user);

    expect(Attendance::where('member_id', $this->member->id)->count())->toBe(1)
        ->and($this->user->notifications()->count())->toBe(0);
});

test('a prepaid visit not yet arrived asks the desk to mark them arrived', function () {
    staffLoginSubscribe($this->member, $this->entry);
    Attendance::factory()->create(['member_id' => $this->member->id, 'event_id' => $this->event->id, 'checked_in_at' => null]);

    Auth::login($this->user);

    expect(staffLoginVisit($this->member)->checked_in_at)->toBeNull()
        ->and($this->user->notifications()->first()->data['title'])->toContain('mark you arrived')
        ->and($this->desk->notifications()->count())->toBe(1);
});
