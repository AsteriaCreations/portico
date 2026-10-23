<?php

use App\Enums\AddOnKind;
use App\Enums\AdmissionOutcome;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\AddOn;
use App\Models\BanException;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $this->event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
});

function housePolicyMember(Category $category, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
    ], $overrides));
}

/**
 * @return array{tone: string, headline: string, detail: string|null, flags: list<string>}
 */
function deskStrip(Member $member, ?Event $event): array
{
    return Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id, 'event_id' => $event?->id])
        ->instance()->statusStrip();
}

test('the desk uses the club\'s House Policy wording for an under-21 member, with :age filled in', function () {
    MembershipSetting::current()->update([
        'house_policy_under_alcohol_age_headline' => 'Mark hand — under :age',
        'house_policy_under_alcohol_age_instructions' => 'Wristband before check-in.',
    ]);
    $member = housePolicyMember($this->irregular, ['dob' => now()->subYears(19)->toDateString()]);

    expect(deskStrip($member, $this->event))
        ->tone->toBe('check')
        ->headline->toBe('Mark hand — under 21')
        ->detail->toBe('Wristband before check-in.')
        ->flags->toBe([]);
});

test('a banned member gets the club\'s wording, and is still not admitted', function () {
    MembershipSetting::current()->update([
        'house_policy_banned_headline' => 'Banned — not tonight',
        'house_policy_banned_instructions' => 'Take no money. Call the duty manager.',
    ]);
    $member = housePolicyMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'x']);

    $component = Livewire::test(CheckIn::class)->fillForm(['member_id' => $member->id, 'event_id' => $this->event->id]);

    expect($component->instance()->statusStrip())
        ->tone->toBe('stop')
        ->headline->toBe('Banned — not tonight')
        ->detail->toBe('Take no money. Call the duty manager.')
        ->and($component->instance()->getDecision()->outcome)->toBe(AdmissionOutcome::Block);
    $component->assertActionHidden('checkIn');
});

test('a banned member\'s one-night exception keeps the shipped wording', function () {
    MembershipSetting::current()->update(['house_policy_banned_headline' => 'Banned — not tonight']);
    $member = housePolicyMember($this->irregular, ['is_banned' => true, 'ban_reason' => 'x']);
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $this->event->id]);

    expect(deskStrip($member, $this->event))->headline->toBe('Acknowledge before admitting');
});

test('a watchlisted member gets the club\'s wording, with :label filled in', function () {
    MembershipSetting::current()->update([
        'watchlist_notify_label' => 'the Signal group',
        'house_policy_watchlist_headline' => 'Watchlist — heads up',
        'house_policy_watchlist_instructions' => 'Post in :label, then tick the box.',
    ]);
    $member = housePolicyMember($this->irregular, ['on_watchlist' => true, 'watchlist_reason' => 'x']);

    expect(deskStrip($member, $this->event))
        ->headline->toBe('Watchlist — heads up')
        ->detail->toBe('Post in the Signal group, then tick the box.');
});

test('a member under the age of majority gets the club\'s wording, at an event and before one is picked', function () {
    MembershipSetting::current()->update(['house_policy_under_majority_headline' => 'Under :age — no entry']);
    $member = housePolicyMember($this->irregular, ['dob' => now()->subYears(16)->toDateString()]);

    expect(deskStrip($member, $this->event))->tone->toBe('stop')->headline->toBe('Under 18 — no entry')
        ->and(deskStrip($member, null))->tone->toBe('stop')->headline->toBe('Under 18 — no entry');
});

test('blank House Policy lines fall back to the shipped wording', function () {
    $watchlisted = housePolicyMember($this->irregular, ['on_watchlist' => true, 'watchlist_reason' => 'x']);

    expect(deskStrip($watchlisted, $this->event))
        ->headline->toBe('Acknowledge before admitting')
        ->detail->toBe('Notify '.MembershipSetting::watchlistNotifyLabel());
});

test('an admin can change the House Policy wording on Membership Settings', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    Livewire::test(MembershipSettings::class)
        ->fillForm(['house_policy_banned_headline' => 'Banned — not tonight'])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->house_policy_banned_headline)->toBe('Banned — not tonight');
});

test('a manager sees the House Policy wording read-only, and a forged change is not saved', function () {
    MembershipSetting::current()->update(['house_policy_banned_headline' => 'Banned — not tonight']);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(MembershipSettings::class)
        ->assertFormFieldDisabled('house_policy_banned_headline')
        ->set('data.house_policy_banned_headline', 'Forged')
        ->callAction('save');

    expect(MembershipSetting::current()->house_policy_banned_headline)->toBe('Banned — not tonight');
});
