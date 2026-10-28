<?php

namespace App\Filament\Admin\Resources\IssueReports\Schemas;

use App\Models\IssueReport;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

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
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage(__('Copied'))
                            ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']),
                    ]),
            ]);
    }
}
