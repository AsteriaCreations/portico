<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\UserBulkImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListUsers extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadUserTemplateAction(),
            $this->bulkUploadUsersAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what bulkUploadUsersAction()/UserBulkImporter
     * actually reads.
     */
    protected function downloadUserTemplateAction(): Action
    {
        return Action::make('downloadUserTemplate')
            ->label('Download user template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', User::class))
            ->action(function (): StreamedResponse {
                return response()->streamDownload(function (): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['name', 'email', 'role', 'member_number_or_username']);
                    fputcsv($handle, ['Jane Smith', 'jane@example.com', 'door', 'jsmith']);
                    fclose($handle);
                }, 'user-upload-template-'.now()->toDateString().'.csv');
            });
    }

    /**
     * Every account it creates gets a random temporary password, listed once
     * in a persistent notification for the uploader to pass on -- the same
     * delivery as UsersTable::resetPasswordAction(). If one is lost, Reset
     * password issues a fresh one.
     */
    protected function bulkUploadUsersAction(): Action
    {
        return Action::make('bulkUploadUsers')
            ->label('Bulk upload users')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalDescription(__('One account per row. Each gets a temporary password, shown once after the upload, and chooses its own at first sign-in. An email that already has an account is skipped, so re-uploading a file never creates anyone twice.'))
            ->schema([
                FileUpload::make('file')
                    ->label('Users file')
                    ->disk('local')
                    ->directory('user-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->visible(fn (): bool => Gate::allows('create', User::class))
            ->action(function (array $data): void {
                abort_unless(Gate::allows('create', User::class), 403);

                $path = Storage::disk('local')->path($data['file']);
                $result = app(UserBulkImporter::class)->import($path, Auth::user());

                $lines = array_map(
                    fn (array $user): string => e($user['name']).' ('.e($user['email']).'): <strong>'.e($user['password']).'</strong>',
                    $result['created'],
                );

                if ($lines) {
                    array_unshift($lines, e(__("Pass these on now — they won't be shown again.")));
                }

                $lines = [...$lines, ...array_map(fn (string $entry): string => e($entry), $result['log'])];

                Notification::make()
                    ->title(trans_choice('Created :count account|Created :count accounts', count($result['created'])))
                    ->body($lines ? new HtmlString(implode('<br>', $lines)) : null)
                    ->persistent()
                    ->success()
                    ->send();

                $this->pruneUploads('user-uploads');
            });
    }
}
