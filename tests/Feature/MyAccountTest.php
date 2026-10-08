<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MyAccount;
use App\Mail\EventEndedSummary;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('every role can open My account', function (Role $role) {
    $user = User::factory()->create(['role' => $role, 'active' => true]);

    $this->actingAs($user)->get('/admin/my-account')->assertSuccessful();
})->with(Role::cases());

test('the user menu links to My account and Change password', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true]);

    $this->actingAs($user)->get('/admin')
        ->assertSuccessful()
        ->assertSee(MyAccount::getUrl(), false)
        ->assertSee('/admin/change-password', false);
});

test('a user can set and clear their own preferred email', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true, 'email' => 'door1@club.local']);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->assertSet('data.sign_in_email', 'door1@club.local')
        ->fillForm(['contact_email' => 'real.person@example.com', 'current_password' => 'password'])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->contact_email)->toBe('real.person@example.com')
        ->and($user->email)->toBe('door1@club.local');

    Livewire::test(MyAccount::class)
        ->fillForm(['contact_email' => '', 'current_password' => 'password'])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->contact_email)->toBeNull();
});

test('the preferred email must be a valid address', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true]);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->fillForm(['contact_email' => 'not-an-email', 'current_password' => 'password'])
        ->callAction('save')
        ->assertHasFormErrors(['contact_email' => 'email']);

    expect($user->refresh()->contact_email)->toBeNull();
});

test('My account cannot change the sign-in email', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true, 'email' => 'door1@club.local']);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->set('data.sign_in_email', 'hijack@example.com')
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->email)->toBe('door1@club.local');
});

test('changing the preferred email needs the current password', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true]);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->fillForm(['contact_email' => 'attacker@example.com'])
        ->callAction('save')
        ->assertHasFormErrors(['current_password' => 'required']);

    Livewire::test(MyAccount::class)
        ->fillForm(['contact_email' => 'attacker@example.com', 'current_password' => 'wrong-password'])
        ->callAction('save')
        ->assertHasFormErrors(['current_password']);

    expect($user->refresh()->contact_email)->toBeNull();
});

test('clearing the preferred email needs the current password too', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true, 'contact_email' => 'real.person@example.com']);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->fillForm(['contact_email' => ''])
        ->callAction('save')
        ->assertHasFormErrors(['current_password' => 'required']);

    expect($user->refresh()->contact_email)->toBe('real.person@example.com');
});

test('saving without changing the preferred email does not ask for the password', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true, 'contact_email' => 'real.person@example.com']);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->assertFormFieldIsHidden('current_password')
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->contact_email)->toBe('real.person@example.com');
});

test('mail goes to the preferred email when set, else the sign-in email', function () {
    $user = User::factory()->create(['email' => 'door1@club.local']);

    expect($user->preferredEmail())->toBe('door1@club.local')
        ->and($user->routeNotificationForMail())->toBe('door1@club.local');

    $user->update(['contact_email' => 'real.person@example.com']);

    expect($user->preferredEmail())->toBe('real.person@example.com')
        ->and($user->routeNotificationForMail())->toBe('real.person@example.com');
});

test('the event-ended summary is emailed to the owner\'s preferred email', function () {
    Mail::fake();

    User::factory()->create([
        'role' => Role::Owner,
        'active' => true,
        'email' => 'owner@club.local',
        'contact_email' => 'owner.inbox@example.com',
    ]);
    Event::factory()->create(['ends_at' => now()->subHour()]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertSent(EventEndedSummary::class, fn ($mail) => $mail->hasTo('owner.inbox@example.com') && ! $mail->hasTo('owner@club.local'));
});

test('club email is on by default', function () {
    $user = User::factory()->create(['email' => 'door1@club.local']);

    expect($user->mailAddress())->toBe('door1@club.local')
        ->and($user->refresh()->email_opt_in)->toBeTrue();
});

test('a user can turn club email off and back on without their password', function () {
    $user = User::factory()->create(['role' => Role::Door, 'active' => true]);

    $this->actingAs($user);

    Livewire::test(MyAccount::class)
        ->assertSet('data.email_opt_in', true)
        ->assertFormFieldIsHidden('current_password')
        ->fillForm(['email_opt_in' => false])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->email_opt_in)->toBeFalse()
        ->and($user->mailAddress())->toBeNull()
        ->and($user->routeNotificationForMail())->toBeNull();

    Livewire::test(MyAccount::class)
        ->assertSet('data.email_opt_in', false)
        ->fillForm(['email_opt_in' => true])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->email_opt_in)->toBeTrue();
});

test('an owner who turned club email off gets the event-ended summary in the app only', function () {
    Mail::fake();

    $owner = User::factory()->create([
        'role' => Role::Owner,
        'active' => true,
        'contact_email' => 'owner.inbox@example.com',
        'email_opt_in' => false,
    ]);
    Event::factory()->create(['ends_at' => now()->subHour()]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertNotSent(EventEndedSummary::class);
    expect($owner->notifications()->count())->toBe(1);
});

test('an unflagged user can still open Change password from the user menu', function () {
    $user = User::factory()->create(['role' => Role::Showrunner, 'active' => true, 'must_change_password' => false]);

    $this->actingAs($user)->get('/admin/change-password')->assertSuccessful();
});
