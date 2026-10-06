<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\CompListRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

test('a manager can open an event\'s view page, with the prepay and comp list tabs, but not its edit page', function () {
    $event = Event::factory()->create();

    $this->get("/admin/events/{$event->id}")
        ->assertSuccessful()
        ->assertSee('Prepay list')
        ->assertSee('Comp list');

    $this->get("/admin/events/{$event->id}/edit")->assertForbidden();
});

test('door staff cannot open an event\'s view page', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]))
        ->get("/admin/events/{$event->id}")
        ->assertForbidden();
});

test('the events list gives a manager View and an admin Edit', function () {
    $event = Event::factory()->create();

    Livewire::test(ListEvents::class)
        ->assertTableActionVisible('view', $event)
        ->assertTableActionHidden('edit', $event);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    Livewire::test(ListEvents::class)
        ->assertTableActionHidden('view', $event)
        ->assertTableActionVisible('edit', $event);
});

test('a manager can add to the prepay list from the view page', function () {
    $event = Event::factory()->create(['entry_fee' => 20, 'pool_fee' => 0]);
    $member = Member::factory()->create();

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => ViewEvent::class,
    ])
        ->assertTableActionVisible('create')
        ->callTableAction('create', data: ['member_id' => $member->id])
        ->assertHasNoTableActionErrors();

    expect(Attendance::where('event_id', $event->id)->where('member_id', $member->id)->exists())->toBeTrue();
});

test('a manager can add to the comp list from the view page', function () {
    $event = Event::factory()->create(['entry_fee' => 20, 'pool_fee' => 0]);
    $member = Member::factory()->create();
    $reason = CompReason::factory()->create();

    Livewire::test(CompListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => ViewEvent::class,
    ])
        ->callTableAction('create', data: ['member_id' => $member->id, 'comp_reason_id' => $reason->id])
        ->assertHasNoTableActionErrors();

    expect(Attendance::where('event_id', $event->id)->where('comp_reason_id', $reason->id)->exists())->toBeTrue();
});

test('an archived event\'s lists stay read-only on the view page', function () {
    $event = Event::factory()->create(['archived_at' => now()]);

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => ViewEvent::class,
    ])->assertTableActionHidden('create');

    Livewire::test(CompListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => ViewEvent::class,
    ])->assertTableActionHidden('create');
});
