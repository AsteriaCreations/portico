<?php

namespace App\Filament\Admin\Resources\Vouchers\Pages;

use App\Filament\Admin\Resources\Vouchers\VoucherResource;
use App\Models\Voucher;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\VoucherBulkImporter;
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

class ListVouchers extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadVoucherTemplateAction(),
            $this->bulkUploadVouchersAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what bulkUploadVouchersAction()/VoucherBulkImporter
     * actually reads.
     */
    protected function downloadVoucherTemplateAction(): Action
    {
        return Action::make('downloadVoucherTemplate')
            ->label('Download voucher template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', Voucher::class))
            ->action(function (): StreamedResponse {
                return response()->streamDownload(function (): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['member_number_or_username', 'amount', 'reason']);
                    fputcsv($handle, ['jsmith', '25', 'Volunteer thank-you, September 2026']);
                    fclose($handle);
                }, 'voucher-upload-template-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadVouchersAction(): Action
    {
        return Action::make('bulkUploadVouchers')
            ->label('Bulk upload vouchers')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalDescription(__('One voucher per row. A row exactly matching a voucher already on file (same member, amount and reason) is skipped, so re-uploading a file never credits anyone twice.'))
            ->schema([
                FileUpload::make('file')
                    ->label('Vouchers file')
                    ->disk('local')
                    ->directory('voucher-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->visible(fn (): bool => Gate::allows('create', Voucher::class))
            ->action(function (array $data): void {
                abort_unless(Gate::allows('create', Voucher::class), 403);

                $path = Storage::disk('local')->path($data['file']);
                $result = app(VoucherBulkImporter::class)->import($path, Auth::user());

                Notification::make()
                    ->title(trans_choice('Issued :count voucher|Issued :count vouchers', $result['created']))
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('voucher-uploads');
            });
    }
}
