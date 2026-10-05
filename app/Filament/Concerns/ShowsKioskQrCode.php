<?php

namespace App\Filament\Concerns;

use App\Models\Member;
use App\Services\KioskQrCode;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

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
}
