<?php

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\EventBulkImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEvents extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadEventTemplateAction(),
            $this->bulkUploadEventsAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what bulkUploadEventsAction()/EventBulkImporter
     * actually reads.
     */
    protected function downloadEventTemplateAction(): Action
    {
        return Action::make('downloadEventTemplate')
            ->label('Download event template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', Event::class))
            ->action(function (): StreamedResponse {
                return response()->streamDownload(function (): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, [
                        'event_date', 'starts_at', 'ends_at', 'name', 'event_type',
                        'entry_fee', 'pool_fee', 'door_prepay_enabled',
                        'showrunner_username', 'host_username', 'notes',
                    ]);
                    fputcsv($handle, [
                        '2026-08-01', '2026-08-01 20:00', '2026-08-01 23:00', 'Summer Social', 'Social',
                        '20', '5', 'N', '', '', '',
                    ]);
                    fclose($handle);
                }, 'event-upload-template-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadEventsAction(): Action
    {
        return Action::make('bulkUploadEvents')
            ->label('Bulk upload events')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                FileUpload::make('file')
                    ->label('Events file')
                    ->disk('local')
                    ->directory('event-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->visible(fn (): bool => Gate::allows('create', Event::class))
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path($data['file']);
                $result = app(EventBulkImporter::class)->import($path, Auth::user());

                Notification::make()
                    ->title("Created {$result['created']} events")
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('event-uploads');
            });
    }
}
