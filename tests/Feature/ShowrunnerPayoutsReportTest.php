<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Enums\Role;
use App\Filament\Admin\Pages\ShowrunnerPayouts;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    ShowrunnerPayoutTier::create(['min_headcount' => 0, 'max_headcount' => 35, 'payout_type' => PayoutType::Voucher, 'payout_value' => 25]);
    ShowrunnerPayoutTier::create(['min_headcount' => 36, 'max_headcount' => 100, 'payout_type' => PayoutType::Percentage, 'payout_value' => 10]);
    ShowrunnerPayoutTier::create(['min_headcount' => 101, 'max_headcount' => null, 'payout_type' => PayoutType::Percentage, 'payout_value' => 15]);

    $this->category = Category::factory()->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

function paidVisits(Event $event, int $count): void
{
    Attendance::factory()->for($event)->recycle(test()->category)->count($count)->create([
        'checked_in_at' => $event->event_date,
        'entry_covered_by' => EntryCoverageSource::None,
        'entry_fee' => $event->entry_fee,
        'entry_coverage' => 0,
    ]);
}

test('opens on past events that had a showrunner, with each payout and the total', function () {
    $showrunner = Member::factory()->create(['category_id' => $this->category->id]);
    $withShowrunner = Event::factory()->create(['event_date' => '2026-09-05', 'entry_fee' => 25, 'showrunner_id' => $showrunner->id]);
    paidVisits($withShowrunner, 39);
    $without = Event::factory()->create(['event_date' => '2026-09-12', 'entry_fee' => 25, 'showrunner_id' => null]);
    paidVisits($without, 50);
    $future = Event::factory()->create(['event_date' => today()->addMonth(), 'entry_fee' => 25, 'showrunner_id' => $showrunner->id]);

    Livewire::test(ShowrunnerPayouts::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$withShowrunner])
        ->assertCanNotSeeTableRecords([$without, $future])
        ->assertSee('4 × $25.00 × 10%')
        ->assertSee('Total for these events: $10.00');
});

test('the All events filter shows every past event, so imported nights can be checked', function () {
    $showrunner = Member::factory()->create(['category_id' => $this->category->id]);
    $withShowrunner = Event::factory()->create(['event_date' => '2026-09-05', 'entry_fee' => 25, 'showrunner_id' => $showrunner->id]);
    paidVisits($withShowrunner, 39); // $10
    $without = Event::factory()->create(['event_date' => '2026-09-12', 'entry_fee' => 20, 'showrunner_id' => null]);
    paidVisits($without, 20); // voucher, $25

    Livewire::test(ShowrunnerPayouts::class)
        ->filterTable('has_showrunner', null)
        ->assertCanSeeTableRecords([$withShowrunner, $without])
        ->assertSee('$25.00 voucher')
        ->assertSee('Total for these events: $35.00');
});

test('is Manager and up, and hidden when showrunner payouts are switched off', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $this->get(ShowrunnerPayouts::getUrl())->assertForbidden();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $this->get(ShowrunnerPayouts::getUrl())->assertSuccessful();

    MembershipSetting::current()->update(['showrunner_payouts_enabled' => false]);
    $this->get(ShowrunnerPayouts::getUrl())->assertForbidden();
});
