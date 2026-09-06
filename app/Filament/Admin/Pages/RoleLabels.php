<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Models\MembershipSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Per-role display-label overrides — the seven roles' underlying enum
 * cases/values, gates, and every "Role::X" reference in code stay exactly
 * as they are; only what staff *see* in the Users form/table and Active
 * Patrons' signed-in-staff line can be aliased, via
 * MembershipSetting::role_labels and Role::displayLabel(). "Showrunner" and
 * "DM" ship with generic defaults (Event Lead, Monitor) precisely so a club
 * that already uses that vernacular can alias them right back.
 */
class RoleLabels extends Page
{
    protected string $view = 'filament.admin.pages.role-labels';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Role Labels';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // Same floor as every other Settings-group page (MembershipSettings,
    // FeatureFlags) -- a display-only cosmetic, not a permissions change.
    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Manager);
    }

    public function mount(): void
    {
        $this->form->fill([
            'role_labels' => MembershipSetting::current()->role_labels ?? [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components(
                collect(Role::cases())
                    ->map(fn (Role $role) => TextInput::make("role_labels.{$role->value}")
                        ->label("\"{$role->getLabel()}\" role")
                        ->placeholder($role->getLabel())
                        ->maxLength(60))
                    ->all()
            );
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Save labels')
            ->action(function (): void {
                // A blank field means "use the default" -- store that as a
                // missing key, not an empty string, so Role::displayLabel()'s
                // ?? actually falls through to getLabel() rather than
                // rendering nothing.
                $labels = collect($this->form->getState()['role_labels'] ?? [])
                    ->filter(fn (?string $label) => filled($label))
                    ->all();

                MembershipSetting::current()->update(['role_labels' => $labels]);

                Notification::make()->title('Role labels saved')->success()->send();
            });
    }
}
