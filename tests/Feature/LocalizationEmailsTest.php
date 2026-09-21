<?php

use App\Enums\Role;
use App\Mail\EventEndedSummary;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Real JSON loader, real lang file: a console command never passes through
// the SetLocale middleware, so it has to apply the installation's language
// itself before rendering email text.
beforeEach(function () {
    $this->originalLangPath = lang_path();
    $this->scratchLangPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-lang-'.uniqid();
    File::makeDirectory($this->scratchLangPath);
    File::put($this->scratchLangPath.DIRECTORY_SEPARATOR.'es.json', json_encode([
        'Event summary: :name — :date' => 'xx-Summary :name / :date',
        'You can’t deactivate your own account.' => 'xx-No self deactivate',
    ], JSON_UNESCAPED_UNICODE));

    app()->useLangPath($this->scratchLangPath);
    app('translator')->getLoader()->addJsonPath($this->scratchLangPath);
});

afterEach(function () {
    app()->useLangPath($this->originalLangPath);
    File::deleteDirectory($this->scratchLangPath);
    app()->setLocale('en');
    Number::useLocale('en');
});

test('applyConfiguredLocale applies an installed locale and ignores a missing one', function () {
    MembershipSetting::current()->update(['locale' => 'de']);
    MembershipSetting::applyConfiguredLocale();

    expect(app()->getLocale())->toBe(config('app.locale'));

    MembershipSetting::current()->update(['locale' => 'es']);
    MembershipSetting::applyConfiguredLocale();

    expect(app()->getLocale())->toBe('es')
        ->and(Number::defaultLocale())->toBe('es');
});

test('the event-ended email is rendered in the installation language even from a console command', function () {
    Mail::fake();
    MembershipSetting::current()->update(['locale' => 'es']);
    User::factory()->create(['active' => true, 'role' => Role::Owner, 'email' => 'owner@example.com']);
    $event = Event::factory()->create(['name' => 'Social', 'event_date' => today(), 'ends_at' => now()->subHour()]);

    Artisan::call('events:notify-ended');

    Mail::assertSent(EventEndedSummary::class, function (EventEndedSummary $mail) use ($event): bool {
        return $mail->envelope()->subject === 'xx-Summary Social / '.$event->event_date->translatedFormat('M j, Y');
    });
});

test('the event-ended email stays English when no language is set', function () {
    Mail::fake();
    User::factory()->create(['active' => true, 'role' => Role::Owner, 'email' => 'owner@example.com']);
    $event = Event::factory()->create(['name' => 'Social', 'event_date' => today(), 'ends_at' => now()->subHour()]);

    Artisan::call('events:notify-ended');

    Mail::assertSent(EventEndedSummary::class, fn (EventEndedSummary $mail): bool => str_starts_with($mail->envelope()->subject, 'Event summary: Social — '));
});

test('a user observer guard message is translated', function () {
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);
    $this->actingAs($owner);
    app()->setLocale('es');

    expect(fn () => $owner->update(['active' => false]))
        ->toThrow(ValidationException::class, 'xx-No self deactivate');
});
