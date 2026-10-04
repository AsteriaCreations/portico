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
     * Every account it creates gets a random temporary password, downloaded
     * once as a CSV for the uploader to pass on (a batch is too long to read
     * off a notification, unlike UsersTable::resetPasswordAction()). If one
     * is lost, Reset password issues a fresh one.
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
            ->action(function (array $data): ?StreamedResponse {
                abort_unless(Gate::allows('create', User::class), 403);

                $path = Storage::disk('local')->path($data['file']);
                $result = app(UserBulkImporter::class)->import($path, Auth::user());

                $lines = array_map(fn (string $entry): string => e($entry), $result['log']);

                if ($result['created']) {
                    array_unshift($lines, e(__("Their temporary passwords are in the file that just downloaded — pass them on, then delete it. They won't be shown again.")));
                }

                Notification::make()
                    ->title(trans_choice('Created :count account|Created :count accounts', count($result['created'])))
                    ->body($lines ? new HtmlString(implode('<br>', $lines)) : null)
                    ->persistent()
                    ->success()
                    ->send();

                $this->pruneUploads('user-uploads');

                return $result['created'] ? $this->temporaryPasswordsDownload($result['created']) : null;
            });
    }

    /**
     * The only copy of the new accounts' temporary passwords: streamed
     * straight to the uploader's browser, never written to the server's
     * disk or logged.
     *
     * @param  list<array{name: string, email: string, password: string}>  $created
     */
    private function temporaryPasswordsDownload(array $created): StreamedResponse
    {
        return response()->streamDownload(function () use ($created): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['name', 'email', 'temporary_password']);

            foreach ($created as $user) {
                fputcsv($handle, [$user['name'], $user['email'], $user['password']]);
            }

            fclose($handle);
        }, 'new-user-passwords-'.now()->format('Y-m-d-His').'.csv');
    }
}
