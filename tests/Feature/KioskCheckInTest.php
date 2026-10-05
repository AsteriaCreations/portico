<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * The kiosk's scan endpoint, hit as the tablet does: a plain JSON POST with
 * the device secret in a header, no session, nobody signed in.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = MembershipSetting::current();
    $this->settings->update(['kiosk_checkin_enabled' => true]);
    $this->secret = $this->settings->regenerateKioskDeviceSecret();

    $this->systemUser = User::factory()->create(['email' => config('membership.system_user_email'), 'active' => false, 'role' => Role::Admin]);
    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    $this->category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);

    $this->event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
    $this->member = kioskSubscriber($this->category, $this->entry);
});

function kioskSubscriber(Category $category, AddOn $entry, array $overrides = []): Member
{
    $member = Member::factory()->create([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'missing_paperwork' => false,
        ...$overrides,
    ]);
    Subscription::factory()->create([
        'member_id' => $member->id,
        'add_on_id' => $entry->id,
        'covered_month' => now()->startOfMonth()->toDateString(),
    ]);
    $member->ensureKioskToken();

    return $member;
}

function scanAtKiosk(object $test, string $token, ?string $secret = null)
{
    return $test->withHeaders(['X-Kiosk-Secret' => $secret ?? $test->secret])
        ->postJson('/kiosk/scan', ['token' => $token]);
}

function kioskAlertsFor(User $user): int
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->count();
}

test('the endpoint does not exist while kiosk check-in is off', function () {
    $this->settings->update(['kiosk_checkin_enabled' => false]);

    scanAtKiosk($this, $this->member->kiosk_token)->assertNotFound();
});

test('a missing or wrong device secret is refused', function () {
    $this->postJson('/kiosk/scan', ['token' => $this->member->kiosk_token])->assertForbidden();
    scanAtKiosk($this, $this->member->kiosk_token, 'not-the-secret')->assertForbidden();

    expect(Attendance::count())->toBe(0);
});

test('regenerating the device secret locks out the old one', function () {
    $old = $this->secret;
    $this->settings->regenerateKioskDeviceSecret();

    scanAtKiosk($this, $this->member->kiosk_token, $old)->assertForbidden();
});

test('the device secret is stored only as a hash and never serialized', function () {
    expect($this->settings->fresh()->kiosk_device_secret_hash)->toBe(hash('sha256', $this->secret))
        ->and($this->settings->fresh()->toArray())->not->toHaveKey('kiosk_device_secret_hash');
});

test('a token is required', function () {
    $this->withHeaders(['X-Kiosk-Secret' => $this->secret])->postJson('/kiosk/scan', [])->assertUnprocessable();
});

test('a subscriber who owes nothing is checked in by the system user with the kiosk payment method', function () {
    scanAtKiosk($this, $this->member->kiosk_token)
        ->assertOk()
        ->assertJson(['status' => 'admitted', 'name' => $this->member->displayName()]);

    $attendance = Attendance::where('member_id', $this->member->id)->sole();
    expect($attendance->event_id)->toBe($this->event->id)
        ->and($attendance->checked_in_by)->toBe($this->systemUser->id)
        ->and($attendance->payment_method)->toBe(PaymentMethod::KIOSK)
        ->and((float) $attendance->amount_paid)->toBe(0.0)
        ->and($attendance->checked_in_at)->not->toBeNull()
        ->and(kioskAlertsFor($this->door))->toBe(0);
});

test('the kiosk payment method exists but is never offered anywhere', function () {
    $kiosk = PaymentMethod::where('code', PaymentMethod::KIOSK)->sole();

    expect($kiosk->active)->toBeFalse()
        ->and($kiosk->available_at_desk)->toBeFalse()
        ->and(PaymentMethod::options())->not->toHaveKey(PaymentMethod::KIOSK);
});

test('scanning again after checking in says so and writes nothing', function () {
    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'admitted']);

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'already_checked_in']);

    expect(Attendance::where('member_id', $this->member->id)->count())->toBe(1);
});

test('an unknown or replaced code is not recognised', function () {
    $old = $this->member->kiosk_token;
    $this->member->regenerateKioskToken();

    scanAtKiosk($this, 'no-such-code')->assertJson(['status' => 'not_recognized', 'name' => null]);
    scanAtKiosk($this, $old)->assertJson(['status' => 'not_recognized']);

    expect(Attendance::count())->toBe(0);
});

test('with no event running the kiosk is closed', function () {
    $this->event->update(['event_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHours(3)]);

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'closed']);

    expect(Attendance::count())->toBe(0);
});

test('with two events running at once the kiosk sends everyone to the desk, without an alert', function () {
    Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'see_staff']);

    expect(Attendance::count())->toBe(0)
        ->and(kioskAlertsFor($this->door))->toBe(0);
});

test('a member without a subscription this month is sent to the desk, without an alert', function () {
    $member = Member::factory()->create(['category_id' => $this->category->id, 'dob' => '1990-01-01']);
    $member->ensureKioskToken();

    scanAtKiosk($this, $member->kiosk_token)->assertJson(['status' => 'see_staff']);

    expect(Attendance::count())->toBe(0)
        ->and(kioskAlertsFor($this->door))->toBe(0);
});

test('a subscriber who would still owe money is sent to the desk', function () {
    // $40 entry against a $25 credit leaves $15 to pay.
    $this->event->update(['entry_fee' => 40]);

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'see_staff']);

    expect(Attendance::count())->toBe(0);
});

test('a comped-category member with no subscription is sent to the desk even though they owe nothing', function () {
    $comped = Category::factory()->create(['name' => 'Staff', 'is_comped' => true]);
    $member = Member::factory()->create(['category_id' => $comped->id, 'dob' => '1990-01-01']);
    $member->ensureKioskToken();

    scanAtKiosk($this, $member->kiosk_token)->assertJson(['status' => 'see_staff']);

    expect(Attendance::count())->toBe(0);
});

test('every non-ok admission decision sends the member to the desk and alerts door and up', function (array $overrides) {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $member = kioskSubscriber($this->category, $this->entry, $overrides);

    scanAtKiosk($this, $member->kiosk_token)
        ->assertJson(['status' => 'see_staff'])
        ->assertJsonMissing(['message' => 'secret reason']);

    expect(Attendance::where('member_id', $member->id)->exists())->toBeFalse()
        ->and(kioskAlertsFor($this->door))->toBe(1)
        ->and(kioskAlertsFor($manager))->toBe(1)
        ->and(kioskAlertsFor($volunteer))->toBe(0)
        ->and(kioskAlertsFor($this->systemUser))->toBe(0);
})->with([
    'watchlist (warn)' => [['on_watchlist' => true, 'watchlist_reason' => 'secret reason']],
    'banned (block)' => [['is_banned' => true, 'ban_reason' => 'secret reason']],
    'deceased (block)' => [['is_deceased' => true]],
    'missing paperwork (capture)' => [['missing_paperwork' => true]],
    'under the alcohol-flag age (flag)' => [['dob' => now()->subYears(19)->toDateString()]],
]);

test('the staff alert never carries a manager-only reason', function () {
    $member = kioskSubscriber($this->category, $this->entry, ['on_watchlist' => true, 'watchlist_reason' => 'secret reason']);

    scanAtKiosk($this, $member->kiosk_token);

    $data = DB::table('notifications')->where('notifiable_id', $this->door->id)->value('data');
    expect($data)->toContain($member->displayName())
        ->and($data)->not->toContain('secret reason');
});

test('a full building sends the member to the desk and alerts staff', function () {
    $this->settings->update(['venue_capacity' => 1]);
    $other = Member::factory()->create(['category_id' => $this->category->id]);
    Attendance::factory()->create(['member_id' => $other->id, 'event_id' => $this->event->id, 'checked_in_at' => now()]);

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'see_staff']);

    expect(Attendance::where('member_id', $this->member->id)->exists())->toBeFalse()
        ->and(kioskAlertsFor($this->door))->toBe(1);
});

test('without the system user nothing is written and the member is sent to the desk', function () {
    $this->systemUser->delete();

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'see_staff']);

    expect(Attendance::count())->toBe(0);
});

test('a future prepay-only event is never checked into from the kiosk', function () {
    $this->event->update(['event_date' => today()->addWeek()->toDateString(), 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(3), 'door_prepay_enabled' => true]);

    scanAtKiosk($this, $this->member->kiosk_token)->assertJson(['status' => 'closed']);

    expect(Attendance::count())->toBe(0);
});

test('the scan endpoint is rate limited', function () {
    foreach (range(1, 30) as $ignored) {
        scanAtKiosk($this, 'no-such-code')->assertOk();
    }

    scanAtKiosk($this, 'no-such-code')->assertTooManyRequests();
});
