<?php

namespace App\Filament\Admin\Resources\Skills\Pages;

use App\Filament\Admin\Resources\Skills\SkillResource;
use App\Models\Skill;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\MemberSkillBulkImporter;
use App\Services\SkillBulkImporter;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSkills extends ListRecords
{
    use PrunesUploadedFiles;

    protected static string $resource = SkillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make($this->uploadActions(
                name: 'Skill',
                labels: [__('Download skill template'), __('Check skill file'), __('Bulk upload skills')],
                file: 'skill',
                allowed: fn (): bool => Gate::allows('create', Skill::class),
                columns: ['name', 'description', 'sort_order', 'active'],
                example: fn (): array => ['First aid', 'Current certificate on file', '10', 'yes'],
                importer: SkillBulkImporter::class,
                resultColumns: fn (array $row): array => [$row['name'], $row['description'], $row['sort_order'], $row['active']],
                uploadDescription: __('One skill per row. A skill already in the catalog is skipped, so re-uploading a file never adds anything twice.'),
                uploadedTitle: fn (int $count): string => trans_choice('Added :count skill|Added :count skills', $count),
            ))
                ->label(__('Upload skills'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
            ActionGroup::make($this->uploadActions(
                name: 'MemberSkill',
                labels: [__('Download member skill template'), __('Check member skill file'), __('Bulk tag members')],
                file: 'member-skill',
                allowed: fn (): bool => Gate::allows('assign-member-skills'),
                columns: ['member_number_or_username', 'skill'],
                example: fn (): array => ['jsmith', Skill::where('active', true)->orderBy('sort_order')->value('name') ?? 'First aid'],
                importer: MemberSkillBulkImporter::class,
                resultColumns: fn (array $row): array => [$row['identifier'], $row['skill'], $row['member'] ?? ''],
                resultExtraHeaders: ['matched_member'],
                uploadDescription: __('One member and one active skill per row. Tags are only ever added, never removed; a member who already has the skill is skipped.'),
                uploadedTitle: fn (int $count): string => trans_choice('Tagged :count member with a skill|Tagged :count members with a skill', $count),
            ))
                ->label(__('Tag members'))
                ->icon(Heroicon::OutlinedTag)
                ->button()
                ->color('gray'),
            CreateAction::make(),
        ];
    }

    /**
     * Download template, Check file (runs the upload's rules without
     * writing, and downloads every row's result) and Bulk upload, for one
     * importer. The template is generated on the fly so its columns can
     * never drift from what the importer reads; the gate is re-checked
     * inside each action.
     *
     * @param  array{0: string, 1: string, 2: string}  $labels  template, check, upload
     * @param  list<string>  $columns
     * @param  class-string<SkillBulkImporter|MemberSkillBulkImporter>  $importer
     * @param  list<string>  $resultExtraHeaders
     * @return list<Action>
     */
    private function uploadActions(
        string $name,
        array $labels,
        string $file,
        Closure $allowed,
        array $columns,
        Closure $example,
        string $importer,
        Closure $resultColumns,
        string $uploadDescription,
        Closure $uploadedTitle,
        array $resultExtraHeaders = [],
    ): array {
        $directory = "{$file}-uploads";
        $upload = fn (): FileUpload => FileUpload::make('file')
            ->label(__('File'))
            ->disk('local')
            ->directory($directory)
            ->acceptedFileTypes([
                'text/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->required();

        return [
            Action::make("download{$name}Template")
                ->label($labels[0])
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible($allowed)
                ->action(function () use ($columns, $example, $file): StreamedResponse {
                    $row = $example();

                    return response()->streamDownload(function () use ($columns, $row): void {
                        $handle = fopen('php://output', 'w');
                        fputcsv($handle, $columns);
                        fputcsv($handle, $row);
                        fclose($handle);
                    }, "{$file}-upload-template-".now()->toDateString().'.csv');
                }),
            Action::make("check{$name}File")
                ->label($labels[1])
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->modalDescription(__('Nothing is changed. You get a results file listing every row and any problem that would make the upload skip it.'))
                ->modalSubmitActionLabel(__('Check and download results'))
                ->schema([$upload()])
                ->visible($allowed)
                ->action(function (array $data) use ($allowed, $importer, $columns, $resultColumns, $resultExtraHeaders, $directory, $file): StreamedResponse {
                    abort_unless($allowed(), 403);

                    $rows = app($importer)->check(Storage::disk('local')->path($data['file']));
                    $this->pruneUploads($directory);

                    $problems = collect($rows)->whereNotNull('problem')->count();

                    Notification::make()
                        ->title(__(':ok of :total rows would be added; :problems with problems', [
                            'ok' => count($rows) - $problems,
                            'total' => count($rows),
                            'problems' => $problems,
                        ]))
                        ->color($problems ? 'warning' : 'success')
                        ->send();

                    return response()->streamDownload(function () use ($rows, $columns, $resultColumns, $resultExtraHeaders): void {
                        $handle = fopen('php://output', 'w');
                        fputcsv($handle, ['row', ...$columns, ...$resultExtraHeaders, 'result']);

                        foreach ($rows as $row) {
                            fputcsv($handle, [$row['row'], ...$resultColumns($row), $row['problem'] ?? 'OK']);
                        }

                        fclose($handle);
                    }, "{$file}-upload-check-".now()->toDateString().'.csv');
                }),
            Action::make("bulkUpload{$name}s")
                ->label($labels[2])
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription($uploadDescription)
                ->schema([$upload()])
                ->visible($allowed)
                ->action(function (array $data) use ($allowed, $importer, $directory, $uploadedTitle): void {
                    abort_unless($allowed(), 403);

                    $result = app($importer)->import(Storage::disk('local')->path($data['file']));

                    Notification::make()
                        ->title($uploadedTitle($result['created']))
                        ->body($result['log'] ? implode("\n", $result['log']) : null)
                        ->success()
                        ->send();

                    $this->pruneUploads($directory);
                }),
        ];
    }
}
