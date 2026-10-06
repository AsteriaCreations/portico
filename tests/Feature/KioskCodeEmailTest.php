<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Mail\KioskCodeEmail;
use App\Models\AddOn;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\KioskCodeMailer;
use App\Services\KioskQrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => true]);
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Mail::fake();
});

/** A real transport, as on a server with MAIL_MAILER=smtp. Tests otherwise run with 'array'. */
function withRealMail(): void
{
    config(['mail.default' => 'smtp']);
}

function kioskEmailSubscriber(AddOn $entry, array $overrides = []): Member
{
    $member = Member::factory()->create(['email' => fake()->unique()->safeEmail(), 'email_opt_in' => true, 'is_active' => true, ...$overrides]);
    Subscription::factory()->create([
        'member_id' => $member->id,
        'add_on_id' => $entry->id,
        'covered_month' => now()->startOfMonth()->toDateString(),
    ]);

    return $member;
}

test('emailing a code sends the qr embedded in the email, creating the code if needed', function () {
    withRealMail();
    $member = kioskEmailSubscriber($this->entry);

    app(KioskCodeMailer::class)->send($member);

    expect($member->refresh()->kiosk_token)->not->toBeNull()
        ->and($member->kiosk_token_emailed_at)->not->toBeNull();

    Mail::assertSent(KioskCodeEmail::class, function (KioskCodeEmail $mail) use ($member): bool {
        return $mail->hasTo($member->email)
            && str_starts_with($mail->qrPng, "\x89PNG")
            && $mail->member->is($member);
    });
});

test('the email renders with the qr inline and without the code in its text', function () {
    $member = kioskEmailSubscriber($this->entry);
    $token = $member->ensureKioskToken();

    $html = (new KioskCodeEmail($member, app(KioskQrCode::class)->png($token)))->render();

    expect($html)->toContain($member->displayName())
        ->and($html)->toContain('<img')
        ->and($html)->not->toContain($token);
});

test('without a real mail transport a send marks nothing, so the member stays pending', function () {
    expect(KioskCodeMailer::isConfigured())->toBeFalse();
    $member = kioskEmailSubscriber($this->entry);

    app(KioskCodeMailer::class)->send($member);

    expect($member->refresh()->kiosk_token_emailed_at)->toBeNull()
        ->and(app(KioskCodeMailer::class)->pendingSubscribers()->pluck('id')->all())->toContain($member->id);
});

test('the batch goes only to active, opted-in subscribers this month with an email who have not been sent one', function () {
    withRealMail();
    $due = kioskEmailSubscriber($this->entry);
    $optedOut = kioskEmailSubscriber($this->entry, ['email_opt_in' => false]);
    $inactive = kioskEmailSubscriber($this->entry, ['is_active' => false]);
    $noEmail = kioskEmailSubscriber($this->entry, ['email' => null]);
    $alreadySent = kioskEmailSubscriber($this->entry);
    $alreadySent->forceFill(['kiosk_token_emailed_at' => now()])->save();
    $lastMonthOnly = Member::factory()->create(['email' => 'old@example.com', 'email_opt_in' => true]);
    Subscription::factory()->create(['member_id' => $lastMonthOnly->id, 'add_on_id' => $this->entry->id, 'covered_month' => now()->subMonth()->startOfMonth()->toDateString()]);

    $result = app(KioskCodeMailer::class)->sendBatch();

    expect($result)->toBe(['sent' => 1, 'failed' => [], 'remaining' => 0]);
    Mail::assertSent(KioskCodeEmail::class, 1);
    Mail::assertSent(KioskCodeEmail::class, fn (KioskCodeEmail $mail): bool => $mail->hasTo($due->email));
});

test('the batch is capped and carries on where it stopped, never sending twice', function () {
    withRealMail();
    foreach (range(1, 3) as $ignored) {
        kioskEmailSubscriber($this->entry);
    }

    expect(app(KioskCodeMailer::class)->sendBatch(2))->toMatchArray(['sent' => 2, 'remaining' => 1])
        ->and(app(KioskCodeMailer::class)->sendBatch(2))->toMatchArray(['sent' => 1, 'remaining' => 0])
        ->and(app(KioskCodeMailer::class)->sendBatch(2))->toMatchArray(['sent' => 0, 'remaining' => 0]);

    Mail::assertSent(KioskCodeEmail::class, 3);
});

test('without a real mail transport the batch sends nothing', function () {
    kioskEmailSubscriber($this->entry);

    expect(app(KioskCodeMailer::class)->sendBatch())->toBe(['sent' => 0, 'failed' => [], 'remaining' => 1]);
    Mail::assertNothingSent();
});

test('a member the mail server refuses is reported, left pending, and the batch continues', function () {
    withRealMail();
    $refused = kioskEmailSubscriber($this->entry, ['username' => 'refused-member']);
    $fine = kioskEmailSubscriber($this->entry);

    Mail::shouldReceive('to')->andReturnUsing(function (string $address) use ($refused) {
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->andReturnUsing(function () use ($address, $refused): void {
            if ($address === $refused->email) {
                throw new RuntimeException('550 mailbox unavailable');
            }
        });

        return $pending;
    });

    $result = app(KioskCodeMailer::class)->sendBatch();

    expect($result)->toBe(['sent' => 1, 'failed' => ['refused-member'], 'remaining' => 1])
        ->and($refused->refresh()->kiosk_token_emailed_at)->toBeNull()
        ->and($fine->refresh()->kiosk_token_emailed_at)->not->toBeNull();
});

test('replacing a code makes the member pending again', function () {
    withRealMail();
    $member = kioskEmailSubscriber($this->entry);
    app(KioskCodeMailer::class)->send($member);

    $member->refresh()->regenerateKioskToken();

    expect($member->refresh()->kiosk_token_emailed_at)->toBeNull();
});

test('door can email one member their code from the desk', function () {
    withRealMail();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $member = kioskEmailSubscriber($this->entry);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionVisible('emailKioskQrCode')
        ->callAction('emailKioskQrCode');

    Mail::assertSent(KioskCodeEmail::class, fn (KioskCodeEmail $mail): bool => $mail->hasTo($member->email));
});

test('the desk sends no email in training mode', function () {
    withRealMail();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    $member = kioskEmailSubscriber($this->entry);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('data.member_id', $member->id)
        ->callAction('emailKioskQrCode');

    Mail::assertNothingSent();
    expect($member->refresh()->kiosk_token)->toBeNull();
});

test('the email action is hidden for a member with no email, and with the flag off', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $member = kioskEmailSubscriber($this->entry, ['email' => null]);

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])->assertActionHidden('emailKioskQrCode');

    $withEmail = kioskEmailSubscriber($this->entry);
    MembershipSetting::current()->update(['kiosk_checkin_enabled' => false]);

    Livewire::test(EditMember::class, ['record' => $withEmail->getRouteKey()])->assertActionHidden('emailKioskQrCode');
});

test('a manager can email the next batch from subscriptions; door cannot', function () {
    withRealMail();
    $subscriber = kioskEmailSubscriber($this->entry);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    expect(Gate::allows('email-kiosk-codes'))->toBeFalse();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    Livewire::test(ListSubscriptions::class)
        ->assertActionVisible('emailKioskCodes')
        ->callAction('emailKioskCodes');

    Mail::assertSent(KioskCodeEmail::class, fn (KioskCodeEmail $mail): bool => $mail->hasTo($subscriber->email));
});

test('the bulk action sends nothing while email is not set up', function () {
    kioskEmailSubscriber($this->entry);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListSubscriptions::class)->callAction('emailKioskCodes');

    Mail::assertNothingSent();
});
