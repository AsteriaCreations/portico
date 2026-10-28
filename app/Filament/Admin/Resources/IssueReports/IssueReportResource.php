<?php

namespace App\Filament\Admin\Resources\IssueReports;

use App\Filament\Admin\Resources\IssueReports\Pages\ListIssueReports;
use App\Filament\Admin\Resources\IssueReports\Pages\ViewIssueReport;
use App\Filament\Admin\Resources\IssueReports\Schemas\IssueReportInfolist;
use App\Filament\Admin\Resources\IssueReports\Tables\IssueReportsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\IssueReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

// List + view only: a report is written only by the desk's "Report a
// problem" via IssueReportService, never a generic Filament form (see
// IssueReportPolicy -- create/update/delete are hard-false for everyone).
// Stays visible with the flag off: a flag never hides collected data.
class IssueReportResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = IssueReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 7;

    public static function infolist(Schema $schema): Schema
    {
        return IssueReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IssueReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssueReports::route('/'),
            'view' => ViewIssueReport::route('/{record}'),
        ];
    }
}
