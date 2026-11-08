<?php

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Every toast sent so far, read the way Notification::assertNotified() does,
 * for a test that checks a body (assertNotified only matches a title or the
 * whole notification). Since Filament 5.11, send() queues to a mailbox that
 * a request's middleware publishes, and the component's mount() no longer
 * reads it, so flush and pull explicitly.
 *
 * @return Collection<string, Notification>
 */
function sentNotifications(): Collection
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotificationsFromSession();

    return $component->notifications;
}
