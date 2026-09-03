<?php

namespace App\Enums;

use App\Models\MembershipSetting;

enum PlanType: string
{
    case Regular = 'regular';
    case Pool = 'pool';

    /**
     * For NEW-subscription creation entry points only (ListSubscriptions::
     * bulkPurchaseAction(), CheckIn::purchaseSubscriptionAction()) -- never
     * used for browsing/editing existing records (SubscriptionForm,
     * SubscriptionsTable's filter), where a legitimate old Pool row must
     * stay viewable/editable regardless of today's flag state. Replicates
     * Filament's own default label for a plain backed enum with no
     * HasLabel (value => case name), so swapping ->options(PlanType::class)
     * for this changes nothing but which cases are offered.
     */
    public static function selectableOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $case) => $case !== self::Pool || MembershipSetting::current()->pool_enabled)
            ->mapWithKeys(fn (self $case) => [$case->value => $case->name])
            ->all();
    }
}
