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
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $this->travelTo(Carbon::parse('2026-10-17 21:00:00'));

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    $irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->first = Member::factory()->create(['category_id' => $irregular->id, 'dob' => '1990-01-01', 'is_banned' => false, 'on_watchlist' => false, 'missing_paperwork' => false]);
    $this->second = Member::factory()->create(['category_id' => $irregular->id, 'dob' => '1990-01-01', 'is_banned' => false, 'on_watchlist' => false, 'missing_paperwork' => false]);
});

/** An event running tonight, 20:00 to 02:00. */
function tonightsEvent(string $name): Event
{
    return Event::factory()->create([
        'name' => $name,
        'event_date' => '2026-10-17',
        'starts_at' => '2026-10-17 20:00:00',
        'ends_at' => '2026-10-18 02:00:00',
        'entry_fee' => 20,
    ]);
}

test('the flag is on by default', function () {
    MembershipSetting::query()->delete();

    expect(MembershipSetting::current()->reset_desk_event_on_member_change)->toBeTrue();
});

test('with two events tonight, picking a different member clears the event', function () {
    $social = tonightsEvent('Social');
    tonightsEvent('Workshop');

    Livewire::test(CheckIn::class)
        ->assertSet('data.event_id', null)
        ->set('data.member_id', $this->first->id)
        ->set('data.event_id', $social->id)
        ->set('data.member_id', $this->second->id)
        ->assertSet('data.event_id', null);
});

test('a member already on one of tonight\'s events gets that event picked', function () {
    tonightsEvent('Social');
    $workshop = tonightsEvent('Workshop');
    // Prepaid for the workshop, not yet arrived.
    Attendance::factory()->for($this->second)->for($workshop)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->second->id)
        ->assertSet('data.event_id', $workshop->id);
});

test('with one event tonight, a later event picked for a prepayment goes back to tonight\'s for the next member', function () {
    $tonight = tonightsEvent('Social');
    $later = Event::factory()->create(['event_date' => '2026-10-24', 'starts_at' => '2026-10-24 20:00:00', 'ends_at' => '2026-10-25 02:00:00', 'door_prepay_enabled' => true]);

    Livewire::test(CheckIn::class)
        ->assertSet('data.event_id', $tonight->id)
        ->set('data.member_id', $this->first->id)
        ->set('data.event_id', $later->id)
        ->set('data.member_id', $this->second->id)
        ->assertSet('data.event_id', $tonight->id);
});

test('with the flag off, the event stays on the last pick', function () {
    MembershipSetting::current()->update(['reset_desk_event_on_member_change' => false]);
    $social = tonightsEvent('Social');
    tonightsEvent('Workshop');

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->first->id)
        ->set('data.event_id', $social->id)
        ->set('data.member_id', $this->second->id)
        ->assertSet('data.event_id', $social->id);
});

test('the Check in button and the toast name the event', function () {
    $social = tonightsEvent('Social');
    tonightsEvent('Workshop');

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $this->first->id)
        ->set('data.event_id', $social->id)
        ->assertActionHasLabel('checkIn', 'Check in to Social')
        ->callAction('checkIn', data: ['payment_method' => 'venmo'])
        ->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->title('Checked in to Social — $20.00 due')->success());
});
