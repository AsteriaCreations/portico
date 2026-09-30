<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Filament\Admin\Pages\RoleLabels;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
});

/**
 * Filament's own client-side check: the hash of the form's current data
 * against the one remembered at load/save.
 */
function unsavedDataHash(array $data): string
{
    return md5((string) str(json_encode($data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));
}

test('the panel warns before leaving a form with unsaved changes', function () {
    expect(Filament::getPanel('admin')->hasUnsavedChangesAlerts())->toBeTrue();
});

test('the event edit page (where add-ons are picked) carries the warning', function () {
    $event = Event::factory()->create();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertSeeHtml('setUpUnsavedDataChangesAlert');
});

test('each settings page carries the warning and starts out with nothing unsaved', function (string $page) {
    $component = Livewire::test($page)->assertSeeHtml('setUpUnsavedDataChangesAlert');

    expect($component->get('savedDataHash'))->toBe(unsavedDataHash($component->get('data')));
})->with([
    'membership settings' => MembershipSettings::class,
    'feature flags' => FeatureFlags::class,
    'role labels' => RoleLabels::class,
]);

test('saving a settings page clears the unsaved-changes state', function () {
    $component = Livewire::test(RoleLabels::class)
        ->fillForm(['role_labels' => ['showrunner' => 'Showrunner']]);

    expect($component->get('savedDataHash'))->not->toBe(unsavedDataHash($component->get('data')));

    $component->callAction('save')->assertHasNoActionErrors();

    expect($component->get('savedDataHash'))->toBe(unsavedDataHash($component->get('data')));
});
