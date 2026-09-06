<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Analytics;
use App\Filament\Admin\Pages\Technical;
use App\Filament\Admin\Resources\AddOns\AddOnResource;
use App\Filament\Admin\Resources\Categories\CategoryResource;
use App\Filament\Admin\Resources\CleaningTasks\CleaningTaskResource;
use App\Filament\Admin\Resources\CompReasons\CompReasonResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\EventTypes\EventTypeResource;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Admin\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Filament\Admin\Resources\Registers\RegisterResource;
use App\Filament\Admin\Resources\RegisterShifts\RegisterShiftResource;
use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\ShowrunnerPayoutTierResource;
use App\Filament\Admin\Resources\Skills\SkillResource;
use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Vouchers\VoucherResource;
use App\Filament\Admin\Widgets\RecordDeparturesWidget;
use App\Models\MembershipSetting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Owner-editable via /admin/membership-settings (manage-org-name
            // gate); null org_name falls back to the existing config('app.name')
            // behavior, so an upgrade never changes what's displayed.
            ->brandName(fn (): string => MembershipSetting::current()->org_name ?: config('app.name'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            // Explicit order for the left rail. Dashboard, the Check-In Desk,
            // and Analytics stay ungrouped and render above all of these.
            // The three catalog/config groups are collapsed by default so the
            // rail isn't a wall of text -- the two operational groups are not.
            ->navigationGroups([
                NavigationGroup::make('Front of House'),
                NavigationGroup::make('Records'),
                NavigationGroup::make('Desk & Money')->collapsed(),
                NavigationGroup::make('Members & Events')->collapsed(),
                NavigationGroup::make('System')->collapsed(),
            ])
            ->databaseNotifications()
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): string => view('filament.admin.footer')->render(),
            )
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
                RecordDeparturesWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // A collapsed "How to use this screen" panel on every resource/page that has
        // no hand-written Blade view of its own to insert <x-screen-instructions>
        // into directly (see CheckIn/ActivePatrons/FeatureFlags/RoleLabels/... for
        // that other case). Scoping to a Resource class fires the hook on that
        // resource's List/Create/Edit/View pages alike (Filament\Resources\Pages\
        // Page::getRenderHookScopes() includes both the concrete page and its
        // resource), so one entry per resource covers every one of its pages.
        foreach ([
            Dashboard::class => 'dashboard',
            Analytics::class => 'analytics',
            Technical::class => 'technical',
            AddOnResource::class => 'add-ons',
            CategoryResource::class => 'categories',
            CleaningTaskResource::class => 'cleaning-tasks',
            CompReasonResource::class => 'comp-reasons',
            EventTypeResource::class => 'event-types',
            EventResource::class => 'events',
            MemberResource::class => 'members',
            PaymentMethodResource::class => 'payment-methods',
            PlanResource::class => 'plans',
            RegisterShiftResource::class => 'register-shifts',
            RegisterResource::class => 'registers',
            ShowrunnerPayoutTierResource::class => 'showrunner-payout-tiers',
            SkillResource::class => 'skills',
            SubscriptionResource::class => 'subscriptions',
            UserResource::class => 'users',
            VoucherResource::class => 'vouchers',
        ] as $scope => $view) {
            $panel->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => view("filament.admin.instructions.{$view}")->render(),
                scopes: $scope,
            );
        }

        return $panel;
    }
}
