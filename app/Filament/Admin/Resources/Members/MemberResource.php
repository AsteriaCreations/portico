<?php

namespace App\Filament\Admin\Resources\Members;

use App\Filament\Admin\Resources\Members\Pages\CreateMember;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Filament\Admin\Resources\Members\RelationManagers\AttendanceRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\BanExceptionsRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\BehaviorNotesRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\MemberPaperworkRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\MemberStatusChangesRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\MemberUsernameChangesRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\PaymentCorrectionsRelationManager;
use App\Filament\Admin\Resources\Members\RelationManagers\WatchlistReviewsRelationManager;
use App\Filament\Admin\Resources\Members\Schemas\MemberForm;
use App\Filament\Admin\Resources\Members\Tables\MembersTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MemberResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AttendanceRelationManager::class,
            BanExceptionsRelationManager::class,
            BehaviorNotesRelationManager::class,
            MemberPaperworkRelationManager::class,
            MemberStatusChangesRelationManager::class,
            MemberUsernameChangesRelationManager::class,
            WatchlistReviewsRelationManager::class,
            PaymentCorrectionsRelationManager::class,
        ];
    }

    /**
     * Watchlist entries whose review date has arrived, for Manager+ (the
     * resource's own floor) to see and raise with an Owner. Computed on each
     * render -- no cron, nothing stored. Hidden at zero.
     */
    public static function getNavigationBadge(): ?string
    {
        $due = Member::query()->watchlistReviewDue()->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Watchlist reviews due');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
