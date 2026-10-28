<?php

namespace App\Filament\Admin\Resources\IssueReports\Pages;

use App\Enums\Role;
use App\Filament\Admin\Resources\IssueReports\IssueReportResource;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Services\IssueReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use RuntimeException;

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
            Action::make('postToGitHub')
                ->label(__('Post to GitHub'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => $this->record->posted_url === null
                    && auth()->user()->role->atLeast(Role::Manager)
                    && app(IssueReportService::class)->canPostToGitHub())
                ->modalHeading(__('Post to GitHub'))
                ->modalDescription(__('Opens an issue with this note and the snapshot. Read the note first: it is sent as written here, so take out anyone\'s name.'))
                ->modalSubmitActionLabel(__('Post'))
                ->fillForm(fn (): array => [
                    'title' => __('Desk problem: :note', ['note' => Str::limit($this->record->note, 70)]),
                    'note' => $this->record->note,
                ])
                ->schema([
                    TextInput::make('title')
                        ->label(__('Issue title'))
                        ->required()
                        ->maxLength(200),
                    Textarea::make('note')
                        ->label(__('What went wrong'))
                        ->required()
                        ->maxLength(2000)
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    abort_unless(auth()->user()->role->atLeast(Role::Manager), 403);

                    try {
                        $url = app(IssueReportService::class)->postToGitHub($this->record, auth()->user(), $data['title'], $data['note']);
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->title(__('Not posted'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Posted to GitHub'))
                        ->actions([
                            Action::make('open')
                                ->label(__('Open issue'))
                                ->url($url, shouldOpenInNewTab: true),
                        ])
                        ->success()
                        ->send();

                    $this->refreshFormData(['posted_url', 'posted_at']);
                }),
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
