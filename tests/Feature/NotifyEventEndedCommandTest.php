<?php

use App\Enums\Role;
use App\Mail\EventEndedSummary;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('an owner is emailed and notified once an event has ended', function () {
    Mail::fake();
    Notification::fake();

    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $event = Event::factory()->create(['ends_at' => now()->subHour(), 'name' => 'Friday Social']);
    Attendance::factory()->for($event)->create(['checked_in_at' => now()->subHours(2), 'amount_paid' => 20]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertSent(EventEndedSummary::class, fn ($mail) => $mail->hasTo($owner->email));
    Notification::assertSentTo($owner, DatabaseNotification::class);

    expect($event->fresh()->ended_notification_sent_at)->not->toBeNull();
});

test('the event\'s assigned showrunner is also notified, via their linked member_id', function () {
    Mail::fake();
    Notification::fake();

    $showrunnerMember = Member::factory()->create();
    $showrunnerUser = User::factory()->create(['role' => Role::Showrunner, 'active' => true, 'member_id' => $showrunnerMember->id]);
    $event = Event::factory()->create(['ends_at' => now()->subHour(), 'showrunner_id' => $showrunnerMember->id]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertSent(EventEndedSummary::class, fn ($mail) => $mail->hasTo($showrunnerUser->email));
    Notification::assertSentTo($showrunnerUser, DatabaseNotification::class);
});

test('an event with no assigned showrunner only notifies the owner(s)', function () {
    Mail::fake();

    User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $event = Event::factory()->create(['ends_at' => now()->subHour(), 'showrunner_id' => null]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertSentCount(1);
});

test('a plain admin is never emailed or notified', function () {
    Mail::fake();
    Notification::fake();

    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    Event::factory()->create(['ends_at' => now()->subHour()]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertNothingSent();
    Notification::assertNothingSentTo($admin);
});

test('an event that has not ended yet is not processed', function () {
    Mail::fake();

    User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $event = Event::factory()->create(['ends_at' => now()->addHour()]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertNothingSent();
    expect($event->fresh()->ended_notification_sent_at)->toBeNull();
});

test('re-running the command is idempotent -- an already-notified event is skipped', function () {
    Mail::fake();

    User::factory()->create(['role' => Role::Owner, 'active' => true]);
    Event::factory()->create(['ends_at' => now()->subHour()]);

    $this->artisan('events:notify-ended')->assertSuccessful();
    Mail::assertSentCount(1);

    $this->artisan('events:notify-ended')->assertSuccessful();
    Mail::assertSentCount(1); // still just the one send from the first run
});

test('an event backfilled with ended_notification_sent_at already set is never (re-)notified', function () {
    Mail::fake();

    User::factory()->create(['role' => Role::Owner, 'active' => true]);
    Event::factory()->create([
        'ends_at' => now()->subHour(),
        'ended_notification_sent_at' => now()->subMinutes(5),
    ]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertNothingSent();
});

test('an event with no recipients is still marked processed, not retried forever', function () {
    Mail::fake();
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);

    $this->artisan('events:notify-ended')->assertSuccessful();

    Mail::assertNothingSent();
    expect($event->fresh()->ended_notification_sent_at)->not->toBeNull();
});
