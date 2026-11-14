<?php

namespace App\Filament\Admin\Resources\IssueReports\Schemas;

use App\Models\IssueReport;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\HtmlString;

class IssueReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Report'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime(),
                        TextEntry::make('reportedBy.name')
                            ->label('Reported by'),
                        TextEntry::make('snapshot.event.name')
                            ->label('Event')
                            ->placeholder(__('—')),
                        TextEntry::make('note')
                            ->label('What went wrong')
                            ->columnSpanFull(),
                        TextEntry::make('posted_url')
                            ->label('GitHub issue')
                            ->url(fn (IssueReport $record): ?string => $record->posted_url, shouldOpenInNewTab: true)
                            ->placeholder(__('Not posted'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Snapshot'))
                    ->description(__('What the desk was showing, without names, contact details, date of birth, notes or reasons. Copy it to share for troubleshooting.'))
                    ->schema([
                        TextEntry::make('snapshot_json')
                            ->hiddenLabel()
                            ->state(fn (IssueReport $record): string => (string) json_encode(
                                ['note' => $record->note, ...$record->snapshot],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ))
                            // Pre-wrap on the <pre> only: on the entry's wrapper it also kept
                            // the template's own indentation, pushing the first line right.
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<pre style="margin: 0; white-space: pre-wrap; overflow-wrap: anywhere;">'.e($state).'</pre>'))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyableState(fn (string $state): string => $state)
                            ->copyMessage(__('Copied')),
                    ]),
            ]);
    }
}
