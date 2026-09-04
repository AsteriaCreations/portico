<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The monthly Manager & Owner subscription perk — a deliberate exception to
 * the nested role model, see docs/BLUEPRINT.md "Monthly Manager
 * & Owner Subscription Perk". One free regular subscription per calendar
 * month per qualifying user, grantable to any subscription-eligible member
 * (including the grantor's own membership record). Availability is a live
 * check against existing subscriptions, never a stored or expiring grant —
 * "use it or lose it" falls out of the check.
 */
class ManagerPerkService
{
    public const CompSource = 'manager_monthly_perk';

    public function isAvailable(User $manager, ?Carbon $month = null): bool
    {
        $month ??= now();

        return ! Subscription::query()
            ->where('recorded_by', $manager->id)
            ->where('comp_source', self::CompSource)
            ->whereBetween('created_at', [$month->clone()->startOfMonth(), $month->clone()->endOfMonth()])
            ->exists();
    }

    public function grant(User $manager, Member $beneficiary, ?string $notes = null): Subscription
    {
        if (! $this->isAvailable($manager)) {
            throw new \RuntimeException('This manager has already used their monthly subscription perk.');
        }

        if (! $beneficiary->isSubscriptionEligible()) {
            throw new \RuntimeException('This member is not yet subscription-eligible.');
        }

        $month = now()->startOfMonth();
        $entry = AddOn::entry();

        if ($beneficiary->hasActiveSubscriptionFor($entry, $month)) {
            throw new \RuntimeException('This member already has regular subscription coverage this month.');
        }

        return Subscription::create([
            'member_id' => $beneficiary->id,
            'add_on_id' => $entry->id,
            'covered_month' => $month->toDateString(),
            'amount_paid' => 0,
            'paid_on' => now(),
            'recorded_by' => $manager->id,
            'comp_source' => self::CompSource,
            'notes' => $notes ?: "Monthly Manager subscription perk gifted to {$beneficiary->username}",
        ]);
    }
}
