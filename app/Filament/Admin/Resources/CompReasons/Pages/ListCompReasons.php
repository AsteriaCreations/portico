<?php

namespace App\Filament\Admin\Resources\CompReasons\Pages;

use App\Filament\Admin\Resources\CompReasons\CompReasonResource;
use App\Models\CompReason;
use App\Services\CompReasonBulkImporter;
use App\Services\Concerns\PrunesUploadedFiles;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCompReasons extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = CompReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadCompReasonTemplateAction(),
            $this->bulkUploadCompReasonsAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what bulkUploadCompReasonsAction()/CompReasonBulkImporter
     * actually reads.
     */
    protected function downloadCompReasonTemplateAction(): Action
    {
        return Action::make('downloadCompReasonTemplate')
            ->label('Download comp reason template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', CompReason::class))
            ->action(function (): StreamedResponse {
                return response()->streamDownload(function (): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['name', 'description', 'grants_voucher_amount', 'sort_order', 'active']);
                    fputcsv($handle, ['House Sub', 'Worked the event in place of staff', '25', '10', 'Y']);
                    fclose($handle);
                }, 'comp-reason-upload-template-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadCompReasonsAction(): Action
    {
        return Action::make('bulkUploadCompReasons')
            ->label('Bulk upload comp reasons')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                FileUpload::make('file')
                    ->label('Comp reasons file')
                    ->disk('local')
                    ->directory('comp-reason-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->visible(fn (): bool => Gate::allows('create', CompReason::class))
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path($data['file']);
                $result = app(CompReasonBulkImporter::class)->import($path);

                Notification::make()
                    ->title("Created {$result['created']} comp reasons")
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('comp-reason-uploads');
            });
    }
}
