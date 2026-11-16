<?php

namespace App\Services\Analytics;

use App\Models\AddOn;
use App\Models\Member;
use App\Models\Subscription;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who the members are, for the Analytics page.
 */
class MembershipAnalytics
{
    /**
     * Member records created in the period.
     */
    public function newMembers(AnalyticsPeriod $period): int
    {
        return $period->constrain(Member::query(), 'created_at')->count();
    }

    /**
     * Active members by category, as it stands today.
     *
     * @return Collection<int, array{name: string, members: int}>
     */
    public function activeByCategory(): Collection
    {
        return DB::table('members')
            ->join('categories', 'categories.id', '=', 'members.category_id')
            ->where('members.is_active', true)
            ->groupBy('categories.id', 'categories.name', 'categories.sort_order')
            ->orderBy('categories.sort_order')
            ->selectRaw('categories.name as name, COUNT(*) as members')
            ->get()
            ->map(fn (object $row): array => ['name' => $row->name, 'members' => (int) $row->members]);
    }

    /**
     * Members holding a subscription to $addOn for the month $month falls in:
     * the same predicate as Member::hasActiveSubscriptionFor() (covered_month
     * is the first of the month).
     */
    public function activeSubscribers(AddOn $addOn, CarbonInterface $month): int
    {
        return Subscription::where('add_on_id', $addOn->id)
            ->whereDate('covered_month', $month->copy()->startOfMonth()->toDateString())
            ->distinct('member_id')
            ->count('member_id');
    }
}
