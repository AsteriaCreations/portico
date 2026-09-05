<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\MemberPaperworkRelationManager;
use App\Filament\Admin\Resources\PaperworkTypes\PaperworkTypeResource;
use App\Models\AddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MemberPaperwork;
use App\Models\PaperworkType;
use App\Models\Plan;
use App\Models\User;
use App\Policies\MemberPaperworkPolicy;
use App\Services\AdmissionPolicy;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->pool->id, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);

    // The seed_paperwork_types_and_backfill migration creates both built-in
    // rows; point the Pool Waiver at this test's Pool add-on (during a
    // RefreshDatabase migrate, add_ons is empty, so its gate is null).
    $this->poolWaiver = PaperworkType::where('name', 'Pool Waiver')->firstOrFail();
    $this->poolWaiver->update(['renewal_months' => 12, 'gates_add_on_id' => $this->pool->id]);
    $this->standard = PaperworkType::where('name', 'Standard Paperwork')->firstOrFail();

    $this->member = Member::factory()->create([
        'category_id' => $this->irregular->id,
        'dob' => '1990-01-01',
        'first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'pat@example.com',
    ]);
});

function signPaperwork(Member $member, PaperworkType $type, string $signedOn): MemberPaperwork
{
    return $member->paperwork()->create(['paperwork_type_id' => $type->id, 'signed_on' => $signedOn]);
}

test('hasValidPaperwork: one-time type is valid forever once signed, invalid until then', function () {
    expect($this->member->hasValidPaperwork($this->standard))->toBeFalse();

    signPaperwork($this->member, $this->standard, '2020-01-01');

    expect($this->member->fresh()->hasValidPaperwork($this->standard))->toBeTrue();
});

test('hasValidPaperwork: an annually-renewed type lapses after its window', function () {
    signPaperwork($this->member, $this->poolWaiver, now()->subMonths(13)->toDateString());
    expect($this->member->fresh()->hasValidPaperwork($this->poolWaiver))->toBeFalse();

    signPaperwork($this->member, $this->poolWaiver, now()->subMonths(11)->toDateString());
    expect($this->member->fresh()->hasValidPaperwork($this->poolWaiver))->toBeTrue();
});

test('canUseAddOn is false for a gated add-on without valid paperwork, true otherwise', function () {
    expect($this->member->canUseAddOn($this->pool))->toBeFalse()
        ->and($this->member->canUseAddOn($this->entry))->toBeTrue();

    signPaperwork($this->member, $this->poolWaiver, now()->toDateString());

    expect($this->member->fresh()->canUseAddOn($this->pool))->toBeTrue();
});

test('PricingService drops the pool line for a member without a valid pool waiver', function () {
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 5]);

    $breakdown = app(PricingService::class)->price($this->member, $event);
    expect($breakdown->addOnLines)->toBeEmpty()
        ->and($breakdown->amountPaid)->toBe(20.0);

    signPaperwork($this->member, $this->poolWaiver, now()->toDateString());

    $breakdown = app(PricingService::class)->price($this->member->fresh(), $event);
    expect($breakdown->addOnLines)->toHaveCount(1)
        ->and($breakdown->amountPaid)->toBe(25.0);
});

test('confirmPaperworkAction clears missing_paperwork and records a Standard Paperwork signing', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $this->member->update(['missing_paperwork' => true]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id])
        ->callAction('confirmPaperwork');

    $this->member->refresh();
    expect($this->member->missing_paperwork)->toBeFalse()
        ->and($this->member->paperwork()->where('paperwork_type_id', $this->standard->id)->count())->toBe(1)
        ->and(app(AdmissionPolicy::class)->needsPaperworkCapture($this->member))->toBeFalse();
});

test('the check-in page warns when a gating waiver is missing', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->id])
        ->assertSee('Pool Waiver missing or expired');
});

test('staff can record a gating waiver signature at the desk, unlocking the add-on', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 5]);

    expect($this->member->canUseAddOn($this->pool))->toBeFalse();

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $this->member->id])
        ->callAction('recordGatedPaperwork', data: [
            'paperwork_type_id' => $this->poolWaiver->id,
            'signed_on' => now()->toDateString(),
        ])
        ->assertHasNoActionErrors()
        ->assertDontSee('Pool Waiver missing or expired');

    $this->member->refresh();
    expect($this->member->canUseAddOn($this->pool))->toBeTrue()
        ->and($this->member->paperwork()->where('paperwork_type_id', $this->poolWaiver->id)->value('recorded_by'))->not->toBeNull()
        ->and(app(PricingService::class)->price($this->member, $event)->amountPaid)->toBe(25.0);
});

test('recordGatedPaperwork is hidden when the member has every gating waiver', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    signPaperwork($this->member, $this->poolWaiver, now()->toDateString());

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $this->member->fresh()->id])
        ->assertActionHidden('recordGatedPaperwork');
});

test('PaperworkTypeResource is Manager+ only', function () {
    expect(PaperworkTypeResource::canViewAny())->toBeFalse();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    expect(PaperworkTypeResource::canViewAny())->toBeFalse();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    expect(PaperworkTypeResource::canViewAny())->toBeTrue();
});

test('a manager can record a signing via the relation manager, but cannot edit or delete one', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    Livewire::test(MemberPaperworkRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->callTableAction('create', data: [
            'paperwork_type_id' => $this->poolWaiver->id,
            'signed_on' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    $row = $this->member->paperwork()->firstOrFail();
    expect($row->recorded_by)->toBe($manager->id)
        ->and(app(MemberPaperworkPolicy::class)->update($manager, $row))->toBeFalse()
        ->and(app(MemberPaperworkPolicy::class)->delete($manager, $row))->toBeFalse();
});
