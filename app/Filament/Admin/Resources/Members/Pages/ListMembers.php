<?php

namespace App\Filament\Admin\Resources\Members\Pages;

use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\MemberBulkImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListMembers extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = MemberResource::class;

    /**
     * A plain display toggle, read by MembersTable's formatStateUsing
     * closures via $livewire — session-only (resets on reload, per-request
     * flips don't persist), doesn't touch stored data, and is independent of
     * the Manager+ role gate that already covers this resource (it only ever
     * hides what the viewer could already see, e.g. for privacy when the
     * screen is visible to others). Its starting value on each fresh page
     * load comes from the club-wide MembershipSetting default, below.
     */
    public bool $piiHidden = true;

    public function mount(): void
    {
        $this->piiHidden = MembershipSetting::current()->hide_member_pii_by_default;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->togglePiiVisibilityAction(),
            $this->exportEmailListAction(),
            $this->exportMemberStatusAction(),
            $this->downloadMemberTemplateAction(),
            $this->bulkUploadMembersAction(),
            CreateAction::make(),
        ];
    }

    protected function togglePiiVisibilityAction(): Action
    {
        return Action::make('togglePiiVisibility')
            ->label(fn (): string => $this->piiHidden ? 'Show personal info' : 'Hide personal info')
            ->icon(fn (): string|BackedEnum => $this->piiHidden ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->action(fn () => $this->piiHidden = ! $this->piiHidden);
    }

    /**
     * A plain CSV, not Filament's built-in queued Exporter — this app has no
     * scheduler/queue worker beyond `sync`, and the list is small enough
     * (~1,000 members) to build and stream in one request. Scoped to
     * email_opt_in members with a non-empty email — an opted-in member with
     * no email on file has nothing to export.
     */
    protected function exportEmailListAction(): Action
    {
        return Action::make('exportEmailList')
            ->label('Export email list (CSV)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (): StreamedResponse {
                $members = Member::query()
                    ->where('email_opt_in', true)
                    ->whereNotNull('email')
                    ->where('email', '!=', '')
                    ->orderBy('username')
                    ->get(['member_number', 'username', 'preferred_name', 'first_name', 'last_name', 'email']);

                return response()->streamDownload(function () use ($members): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['Member Number', 'Username', 'Preferred Name', 'First Name', 'Last Name', 'Email']);

                    foreach ($members as $member) {
                        fputcsv($handle, [
                            $member->member_number,
                            $member->username,
                            $member->preferred_name,
                            $member->first_name,
                            $member->last_name,
                            $member->email,
                        ]);
                    }

                    fclose($handle);
                }, 'email-opt-in-list-'.now()->toDateString().'.csv');
            });
    }

    /**
     * The status report the blueprint's "Still open" backlog asked for --
     * deliberately reads whatever the table's own filters/search/sort are
     * currently set to (Filament's own getTableQueryForExport(), the same
     * mechanism its built-in Exporter would use) rather than a second,
     * separate criteria picker that could drift out of sync with
     * MembersTable's filters. Full detail, not just the boolean flags --
     * this whole page is already Manager+-only, the same bar the on-screen
     * ban_reason/watchlist_reason/dob columns already sit behind.
     */
    protected function exportMemberStatusAction(): Action
    {
        return Action::make('exportMemberStatus')
            ->label('Export member list (CSV)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (): StreamedResponse {
                $members = $this->getTableQueryForExport()->with('category')->get();

                return response()->streamDownload(function () use ($members): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, [
                        'Member Number', 'Username', 'Preferred Name', 'First Name', 'Last Name', 'Email', 'Category',
                        'Active', 'Banned', 'Ban Reason', 'Watchlist', 'Watchlist Reason', 'Deceased',
                        'Missing Paperwork', 'Subscription Eligible', 'On Probation', 'DOB',
                    ]);

                    foreach ($members as $member) {
                        fputcsv($handle, [
                            $member->member_number,
                            $member->username,
                            $member->preferred_name,
                            $member->first_name,
                            $member->last_name,
                            $member->email,
                            $member->category?->name,
                            $member->is_active ? 'Yes' : 'No',
                            $member->isCurrentlyBanned() ? 'Yes' : 'No',
                            $member->ban_reason,
                            $member->on_watchlist ? 'Yes' : 'No',
                            $member->watchlist_reason,
                            $member->is_deceased ? 'Yes' : 'No',
                            $member->missing_paperwork ? 'Yes' : 'No',
                            $member->subscription_eligible ? 'Yes' : 'No',
                            $member->isOnProbation() ? 'Yes' : 'No',
                            $member->dob?->toDateString(),
                        ]);
                    }

                    fclose($handle);
                }, 'member-status-extract-'.now()->toDateString().'.csv');
            });
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what bulkUploadMembersAction()/MemberBulkImporter
     * actually reads.
     */
    protected function downloadMemberTemplateAction(): Action
    {
        return Action::make('downloadMemberTemplate')
            ->label('Download member template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', Member::class))
            ->action(function (): StreamedResponse {
                return response()->streamDownload(function (): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, [
                        'username', 'first_name', 'last_name', 'preferred_name', 'email', 'email_opt_in',
                        'category', 'sponsor_username', 'member_number', 'date_vetted', 'dob', 'paperwork_date',
                        'is_active', 'subscription_eligible', 'on_watchlist', 'watchlist_reason', 'is_banned',
                        'ban_reason', 'probation_override_start', 'missing_paperwork', 'is_deceased',
                        'hospitality_note', 'notes',
                    ]);
                    fputcsv($handle, [
                        'jsmith', 'Jane', 'Smith', 'Jane', 'jane@example.com', 'Y',
                        'Regular', '', '', '2026-01-15', '1990-05-20', '',
                        'Y', 'N', 'N', '', 'N',
                        '', '', 'N', 'N',
                        '', '',
                    ]);
                    fclose($handle);
                }, 'member-upload-template-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadMembersAction(): Action
    {
        return Action::make('bulkUploadMembers')
            ->label('Bulk upload members')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                FileUpload::make('file')
                    ->label('Members file')
                    ->disk('local')
                    ->directory('member-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->visible(fn (): bool => Gate::allows('create', Member::class))
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path($data['file']);
                $result = app(MemberBulkImporter::class)->import($path);

                Notification::make()
                    ->title("Created {$result['created']} members")
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('member-uploads');
            });
    }
}
