<?php

namespace App\Filament\Admin\Resources\Categories\Pages;

use App\Filament\Admin\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Services\CategoryBulkImporter;
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

class ListCategories extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadCategoryTemplateAction(),
            $this->bulkUploadCategoriesAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what bulkUploadCategoriesAction()/CategoryBulkImporter
     * actually reads.
     */
    protected function downloadCategoryTemplateAction(): Action
    {
        return Action::make('downloadCategoryTemplate')
            ->label('Download category template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', Category::class))
            ->action(function (): StreamedResponse {
                return response()->streamDownload(function (): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['name', 'description', 'is_comped', 'sort_order', 'active']);
                    fputcsv($handle, ['Sponsor', 'Sponsoring member category', 'N', '10', 'Y']);
                    fclose($handle);
                }, 'category-upload-template-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadCategoriesAction(): Action
    {
        return Action::make('bulkUploadCategories')
            ->label('Bulk upload categories')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                FileUpload::make('file')
                    ->label('Categories file')
                    ->disk('local')
                    ->directory('category-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->visible(fn (): bool => Gate::allows('create', Category::class))
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path($data['file']);
                $result = app(CategoryBulkImporter::class)->import($path);

                Notification::make()
                    ->title("Created {$result['created']} categories")
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('category-uploads');
            });
    }
}
