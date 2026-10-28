<?php

namespace App\Filament\Admin\Resources\IssueReports\Pages;

use App\Enums\Role;
use App\Filament\Admin\Resources\IssueReports\IssueReportResource;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Services\IssueReportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewIssueReport extends ViewRecord
{
    protected static string $resource = IssueReportResource::class;

    /**
     * Owner only: matches the snapshot's member pseudonym back to the real
     * member on this installation, to reproduce the problem against the
     * record itself. The snapshot never holds the id, so this is the one way
     * back, and it works only with this installation's APP_KEY.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('findMember')
                ->label(__('Find this member'))
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()->role === Role::Owner && filled($this->record->snapshot['member']['ref'] ?? null))
                ->action(function (): void {
                    abort_unless(auth()->user()->role === Role::Owner, 403);

                    $member = app(IssueReportService::class)->findMemberByRef((string) ($this->record->snapshot['member']['ref'] ?? ''));

                    if (! $member) {
                        Notification::make()
                            ->title(__('No member matches this report'))
                            ->body(__('They may have been deleted, or the report came from another installation.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->redirect(MemberResource::getUrl('edit', ['record' => $member]));
                }),
        ];
    }
}
