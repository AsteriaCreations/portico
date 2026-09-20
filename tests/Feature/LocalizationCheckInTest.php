<?php

use App\Enums\AddOnCoverageSource;
use App\Enums\AdmissionOutcome;
use App\Enums\Capability;
use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\AdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// A fixture language registered in memory -- "xx" has no lang file, so this
// exercises the __() call sites themselves rather than any shipped translation.
// setLoaded(), not addLines(): addLines() splits keys on "." and would nest a
// key that contains a period, unlike the flat JSON loader.
beforeEach(function () {
    app('translator')->setLoaded(['*' => ['*' => ['xx' => [
        'Comp' => 'xx-Comp',
        'Event Comp' => 'xx-Event Comp',
        'Percentage' => 'xx-Percentage',
        'Cleaning Crew' => 'xx-Cleaning Crew',
        'Monitor' => 'xx-Monitor',
        'Check-In Desk' => 'xx-Check-In Desk',
        'Training mode — practice freely. Nothing you do here is saved.' => 'xx-Training banner',
        'Not yet subscription-eligible (:attended/:threshold events attended)' => 'xx-Ineligible :attended of :threshold',
        'Under :age — no admittance' => 'xx-Underage :age',
        'Notify :label' => 'xx-Notify :label',
    ]]]]);
});

afterEach(function () {
    app()->setLocale('en');
});

test('enum labels come from the translator and fall back to English', function () {
    expect(EntryCoverageSource::EventComp->getLabel())->toBe('Event Comp')
        ->and(AddOnCoverageSource::None->getLabel())->toBe('None');

    app()->setLocale('xx');

    expect(EntryCoverageSource::EventComp->getLabel())->toBe('xx-Event Comp')
        ->and(AddOnCoverageSource::Comp->getLabel())->toBe('xx-Comp')
        ->and(PayoutType::Percentage->getLabel())->toBe('xx-Percentage')
        ->and(Capability::CleaningCrew->getLabel())->toBe('xx-Cleaning Crew')
        // Untranslated in the fixture, so it falls back to the English source.
        ->and(PayoutType::Voucher->getLabel())->toBe('Voucher');
});

test('a club role alias still wins over the translated default label', function () {
    app()->setLocale('xx');

    expect(Role::DM->getLabel())->toBe('xx-Monitor')
        ->and(Role::DM->displayLabel())->toBe('xx-Monitor');

    MembershipSetting::current()->update(['role_labels' => ['dm' => 'Dungeon Master']]);

    expect(Role::DM->displayLabel())->toBe('Dungeon Master');
});

test('admission decision messages are translated', function () {
    Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    config(['membership.watchlist_notify_label' => 'GroupMe']);
    $event = Event::factory()->create(['event_date' => today()]);

    $underage = Member::factory()->create(['dob' => today()->subYears(10)]);
    $watchlisted = Member::factory()->create(['dob' => '1990-01-01', 'on_watchlist' => true]);

    app()->setLocale('xx');
    $policy = app(AdmissionPolicy::class);

    $blocked = $policy->decide($underage, $event);
    $warned = $policy->decide($watchlisted, $event);

    expect($blocked->outcome)->toBe(AdmissionOutcome::Block)
        ->and($blocked->message)->toBe('xx-Underage '.MembershipSetting::current()->age_of_majority)
        ->and($warned->message)->toBe('xx-Notify GroupMe');
});

test('the check-in desk page renders translated copy and title', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    app()->setLocale('xx');

    expect(CheckIn::getNavigationLabel())->toBe('xx-Check-In Desk');

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->assertSee('xx-Check-In Desk')
        ->assertSee('xx-Training banner')
        ->assertDontSee('Nothing you do here is saved.');
});

test('the check-in desk keeps its English copy under the default locale', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    expect(CheckIn::getNavigationLabel())->toBe('Check-In Desk');

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->assertSee('Training mode — practice freely. Nothing you do here is saved.');
});

test('an ineligible member note interpolates its counts through the translator', function () {
    Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $member = Member::factory()->create(['dob' => '1990-01-01']);
    app()->setLocale('xx');

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertSee('xx-Ineligible 0 of '.MembershipSetting::current()->subscription_eligibility_threshold);
});
