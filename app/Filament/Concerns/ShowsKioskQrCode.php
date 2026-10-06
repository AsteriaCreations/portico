<?php

namespace App\Filament\Concerns;

use App\Models\Member;
use App\Services\KioskCodeMailer;
use App\Services\KioskQrCode;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The "Kiosk QR code" action, shared by the Check-In Desk (Door+) and the
 * Member edit page (Manager+). Opening it creates the member's code if they
 * don't have one yet (Member::ensureKioskToken()) -- a write, so it re-checks
 * the manage-kiosk-token gate server-side and honours the desk's training
 * mode -- then shows the QR with a link to a printable card. Showing it again
 * reuses the same code, so a card already printed keeps working.
 */
trait ShowsKioskQrCode
{
    /**
     * @param  Closure(): ?Member  $resolveMember  the member whose code to show
     * @param  Closure(): bool|null  $isPractice  true when a missing code must not be created (the desk's training mode)
     */
    protected function makeKioskQrCodeAction(Closure $resolveMember, ?Closure $isPractice = null): Action
    {
        return Action::make('kioskQrCode')
            ->label('Kiosk QR code')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('gray')
            ->visible(fn (): bool => $resolveMember() !== null && Gate::allows('manage-kiosk-token'))
            ->mountUsing(function () use ($resolveMember, $isPractice): void {
                abort_unless(Gate::allows('manage-kiosk-token'), 403);

                $member = $resolveMember();
                abort_unless($member, 404);

                if ($member->kiosk_token === null && $isPractice && $isPractice()) {
                    Notification::make()
                        ->title(__('Practice only — nothing saved'))
                        ->body(__('Practice: this member has no kiosk code yet, so none was created.'))
                        ->warning()
                        ->send();

                    return;
                }

                $member->ensureKioskToken();
            })
            ->modalHeading(fn (): string => __('Kiosk QR code — :name', ['name' => $resolveMember()?->displayName() ?? '']))
            ->modalContent(function () use ($resolveMember) {
                $member = $resolveMember();

                return view('filament.admin.kiosk-qr-code', [
                    'member' => $member,
                    'qrDataUri' => $member?->kiosk_token ? app(KioskQrCode::class)->pngDataUri($member->kiosk_token) : null,
                    'cardUrl' => $member?->kiosk_token ? route('kiosk-qr-card', $member) : null,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /**
     * Emails the member their kiosk code (KioskCodeMailer), creating it if
     * they have none. Sending is an outside effect, so the desk's training
     * mode sends nothing. Until the server has a real mail transport the
     * email only reaches the log, and the confirmation says so.
     *
     * @param  Closure(): ?Member  $resolveMember  the member to email
     * @param  Closure(): bool|null  $isPractice  true to send nothing (the desk's training mode)
     */
    protected function makeEmailKioskQrCodeAction(Closure $resolveMember, ?Closure $isPractice = null): Action
    {
        return Action::make('emailKioskQrCode')
            ->label('Email kiosk QR code')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (): bool => filled($resolveMember()?->email) && Gate::allows('manage-kiosk-token'))
            ->requiresConfirmation()
            ->modalHeading(__('Email the kiosk QR code?'))
            ->modalDescription(fn (): string => KioskCodeMailer::isConfigured()
                ? __('Sends the member\'s kiosk code to the email address on file.')
                : __('Email isn\'t set up on this server yet, so this only writes the email to the server\'s log. Show or print the code instead.'))
            ->modalSubmitActionLabel(__('Send'))
            ->action(function () use ($resolveMember, $isPractice): void {
                abort_unless(Gate::allows('manage-kiosk-token'), 403);

                $member = $resolveMember();
                abort_unless($member && filled($member->email), 404);

                if ($isPractice && $isPractice()) {
                    Notification::make()
                        ->title(__('Practice only — nothing saved'))
                        ->body(__('Practice: no email was sent.'))
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    app(KioskCodeMailer::class)->send($member);
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title(__('The email couldn\'t be sent'))
                        ->body(__('The mail server refused it. Show or print the code instead.'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(KioskCodeMailer::isConfigured() ? __('Kiosk code emailed') : __('Email isn\'t set up — written to the log only'))
                    ->success()
                    ->send();
            });
    }
}
