<?php

namespace App\Filament\Admin\Resources\RoleTasks\Pages;

use App\Filament\Admin\Resources\RoleTasks\RoleTaskResource;
use App\Models\RoleTask;
use App\Models\ShiftRole;
use App\Models\TaskArea;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\RoleTaskBulkImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListRoleTasks extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = RoleTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadRoleTaskTemplateAction(),
            $this->checkRoleTaskFileAction(),
            $this->bulkUploadRoleTasksAction(),
            CreateAction::make(),
        ];
    }

    /**
     * The upload can create areas as well as tasks, so it needs both.
     */
    private function canUploadRoleTasks(): bool
    {
        return Gate::allows('create', RoleTask::class) && Gate::allows('create', TaskArea::class);
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what RoleTaskBulkImporter actually reads.
     */
    protected function downloadRoleTaskTemplateAction(): Action
    {
        return Action::make('downloadRoleTaskTemplate')
            ->label('Download task template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->canUploadRoleTasks())
            ->action(function (): StreamedResponse {
                $role = ShiftRole::where('active', true)->orderBy('sort_order')->orderBy('name')->value('name') ?? 'Cleaning';

                return response()->streamDownload(function () use ($role): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['shift_role', 'task', 'area', 'details', 'sort_order']);
                    fputcsv($handle, [$role, 'Mop the floor', 'Bathrooms', 'Bleach only, gloves in the cabinet', '10']);
                    fclose($handle);
                }, 'task-upload-template-'.now()->toDateString().'.csv');
            });
    }

    /**
     * Runs the upload's own rules over a file without adding anything, and
     * downloads every row with its result, including each area it would
     * create, so a file can be fixed before the real upload.
     */
    protected function checkRoleTaskFileAction(): Action
    {
        return Action::make('checkRoleTaskFile')
            ->label('Check task file')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->modalDescription(__('Nothing is added. You get a results file listing every row, any area it would create, and any problem that would make the upload skip it.'))
            ->modalSubmitActionLabel(__('Check and download results'))
            ->schema([$this->roleTaskFileUpload()])
            ->visible(fn (): bool => $this->canUploadRoleTasks())
            ->action(function (array $data): StreamedResponse {
                abort_unless($this->canUploadRoleTasks(), 403);

                $rows = app(RoleTaskBulkImporter::class)->check(Storage::disk('local')->path($data['file']));
                $this->pruneUploads('role-task-uploads');

                $problems = collect($rows)->whereNotNull('problem')->count();

                Notification::make()
                    ->title(__(':ok of :total rows would be added; :problems with problems', [
                        'ok' => count($rows) - $problems,
                        'total' => count($rows),
                        'problems' => $problems,
                    ]))
                    ->color($problems ? 'warning' : 'success')
                    ->send();

                return response()->streamDownload(function () use ($rows): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['row', 'shift_role', 'task', 'area', 'details', 'sort_order', 'result']);

                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row['row'],
                            $row['role'],
                            $row['task'],
                            $row['area'],
                            $row['details'],
                            $row['sort_order'],
                            $row['problem'] ?? ($row['note'] ? 'OK, '.$row['note'] : 'OK'),
                        ]);
                    }

                    fclose($handle);
                }, 'task-upload-check-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadRoleTasksAction(): Action
    {
        return Action::make('bulkUploadRoleTasks')
            ->label('Bulk upload tasks')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalDescription(__('One task per row, for an active shift role. An area not yet on file is created. A task already on that role\'s list is skipped, so re-uploading a file never adds anything twice.'))
            ->schema([$this->roleTaskFileUpload()])
            ->visible(fn (): bool => $this->canUploadRoleTasks())
            ->action(function (array $data): void {
                abort_unless($this->canUploadRoleTasks(), 403);

                $result = app(RoleTaskBulkImporter::class)->import(Storage::disk('local')->path($data['file']));

                Notification::make()
                    ->title(trans_choice('Added :count task|Added :count tasks', $result['created']))
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('role-task-uploads');
            });
    }

    private function roleTaskFileUpload(): FileUpload
    {
        return FileUpload::make('file')
            ->label('Tasks file')
            ->disk('local')
            ->directory('role-task-uploads')
            ->acceptedFileTypes([
                'text/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->required();
    }
}
