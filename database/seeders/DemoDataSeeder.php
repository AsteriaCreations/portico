<?php

namespace Database\Seeders;

use App\Enums\CompRequestStatus;
use App\Enums\EntryCoverageSource;
use App\Enums\PoolCoverageSource;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Category;
use App\Models\CompReason;
use App\Models\CompRequest;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Realistic sample data for local development / demoing the admin panel —
 * NOT called from DatabaseSeeder::run(), so a fresh production install
 * still seeds only the minimal lookup data it needs (see CONTRIBUTING.md's
 * "vanilla Laravel" rule). Run on demand:
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Deliberately skips the audit/derived-log tables (member_status_changes,
 * member_username_changes, ban_exceptions, occupancy_adjustments,
 * notifications, command_runs) — those represent something that actually
 * happened, and are better exercised by clicking through the app for real
 * (ban a member, check someone in, run a console command) than backfilled
 * with disconnected synthetic rows.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::all()->keyBy('name');
        $eventTypes = EventType::all();
        $compReasons = CompReason::all();
        $addOns = AddOn::all();

        $users = $this->seedUsers();
        $members = $this->seedMembers($categories);
        $events = $this->seedEvents($eventTypes, $members, $users);
        $this->seedAttendance($events, $members, $users, $compReasons, $addOns);
        $this->seedSubscriptions($members, $users);
        $this->seedVouchers($members, $users);
        $this->seedCompRequests($events, $members, $users, $compReasons);
        $this->seedRegistersAndShifts($users);
    }

    /**
     * @return Collection<int, User>
     */
    private function seedUsers(): Collection
    {
        $roles = [Role::Owner, Role::Admin, Role::Manager, Role::Manager, Role::Door, Role::Door, Role::DM, Role::Volunteer];

        return collect($roles)->map(fn (Role $role, int $i) => User::factory()->create([
            'name' => fake()->name(),
            'email' => "demo-{$role->value}-{$i}@example.com",
            'role' => $role,
            'active' => true,
        ]));
    }

    /**
     * @return Collection<int, Member>
     */
    private function seedMembers(Collection $categories): Collection
    {
        $irregular = $categories['Irregular'];
        $guest = $categories['Guest'];
        $prospective = $categories['Prospective'];
        // Not $categories->only([...]) -- Eloquent Collection overrides
        // only() to filter by primary key, not array key, so it silently
        // returns nothing against a keyBy('name') collection.
        $staffLike = $categories->filter(fn (Category $category, string $name) => in_array($name, ['Staff', 'Manager', 'Owner', 'Emeritus'], true))->values();

        // The general membership base — most attendance/subscriptions below
        // draw from this pool.
        $regulars = Member::factory()->count(45)->create(['category_id' => $irregular->id]);

        // A few flagged states, so the resources/reports that surface them
        // (watchlist icon column, ban exclusion on Showrunner comps, under-21
        // flag at check-in) have something real to show.
        $banned = Member::factory()->count(2)->create([
            'category_id' => $irregular->id,
            'is_banned' => true,
            'ban_reason' => fake()->randomElement(['Repeated harassment complaints', 'Violated house rules at last event']),
        ]);
        $watchlisted = Member::factory()->count(2)->create([
            'category_id' => $irregular->id,
            'on_watchlist' => true,
            'watchlist_reason' => 'Reported boundary-pushing at a recent event — monitor, not yet actionable.',
        ]);
        $under21 = Member::factory()->count(3)->create([
            'category_id' => $irregular->id,
            'dob' => fake()->dateTimeBetween('-20 years', '-18 years')->format('Y-m-d'),
        ]);
        $eligible = Member::factory()->count(6)->create([
            'category_id' => $irregular->id,
            'subscription_eligible' => true,
        ]);
        $staff = Member::factory()->count(4)->create([
            'category_id' => fn () => $staffLike->random()->id,
        ]);

        // Prospective — half still incomplete (exercises the "Save & promote"
        // capture flow at check-in), half already filled in but not yet
        // promoted.
        $prospectiveIncomplete = Member::factory()->count(3)->create([
            'category_id' => $prospective->id,
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'dob' => null,
        ]);
        $prospectiveComplete = Member::factory()->count(2)->create(['category_id' => $prospective->id]);

        // Guests, each sponsored by one of the regulars.
        $guests = collect(range(1, 4))->map(fn () => Member::factory()->create([
            'category_id' => $guest->id,
            'sponsor_id' => $regulars->random()->id,
        ]));

        return $regulars
            ->concat($banned)
            ->concat($watchlisted)
            ->concat($under21)
            ->concat($eligible)
            ->concat($staff)
            ->concat($prospectiveIncomplete)
            ->concat($prospectiveComplete)
            ->concat($guests)
            ->values();
    }

    /**
     * @return Collection<int, Event>
     */
    private function seedEvents(Collection $eventTypes, Collection $members, Collection $users): Collection
    {
        // A real login-capable Showrunner account, linked to a member — lets
        // /admin/showrunner-comp-requests be demoed end to end, not just the
        // event's own showrunner_id relation.
        $showrunnerMember = $members->where('category.name', 'Irregular')->random();
        User::factory()->create([
            'name' => $showrunnerMember->first_name.' '.$showrunnerMember->last_name,
            'email' => 'demo-showrunner@example.com',
            'role' => Role::Showrunner,
            'member_id' => $showrunnerMember->id,
            'active' => true,
        ]);

        $past = collect(range(10, 1))->map(function (int $weeksAgo) use ($eventTypes) {
            $date = today()->subWeeks($weeksAgo)->next('Saturday');
            $type = $eventTypes->random();
            $isPoolEvent = $type->name === 'Pool Social';

            return Event::create([
                'event_date' => $date,
                'starts_at' => $date->copy()->setTime(20, 0),
                'ends_at' => $date->copy()->addDay()->setTime(2, 0),
                'name' => fake()->randomElement(['Friday Night Social', 'Members Mixer', 'Themed Social', 'Community Night']),
                'event_type_id' => $type->id,
                'entry_fee' => 20,
                'pool_fee' => $isPoolEvent ? 10 : 0,
            ]);
        });

        $future = collect([
            Event::create([
                'event_date' => today()->addWeeks(2)->next('Saturday'),
                'starts_at' => today()->addWeeks(2)->next('Saturday')->setTime(20, 0),
                'ends_at' => today()->addWeeks(2)->next('Saturday')->addDay()->setTime(2, 0),
                'name' => 'Upcoming Social',
                'event_type_id' => $eventTypes->firstWhere('name', 'Social')?->id ?? $eventTypes->first()->id,
                'entry_fee' => 20,
                'pool_fee' => 0,
                'door_prepay_enabled' => true,
            ]),
            Event::create([
                'event_date' => today()->addWeeks(3)->next('Saturday'),
                'starts_at' => today()->addWeeks(3)->next('Saturday')->setTime(20, 0),
                'ends_at' => today()->addWeeks(3)->next('Saturday')->addDay()->setTime(2, 0),
                'name' => 'Showrunner Special',
                'event_type_id' => $eventTypes->firstWhere('name', 'Special')?->id ?? $eventTypes->first()->id,
                'entry_fee' => 25,
                'pool_fee' => 0,
                'showrunner_id' => $showrunnerMember->id,
                'comp_list_due_at' => today()->addWeeks(3)->subDays(3),
            ]),
        ]);

        return $past->concat($future)->values();
    }

    private function seedAttendance(Collection $events, Collection $members, Collection $users, Collection $compReasons, Collection $addOns): void
    {
        $attendablePool = $members->reject(fn (Member $m) => $m->is_banned || $m->category->name === 'Prospective');
        $staffIds = $users->pluck('id');

        foreach ($events as $event) {
            if ($event->event_date->isFuture()) {
                continue;
            }

            $attendees = $attendablePool->random(min(20, $attendablePool->count()));

            foreach ($attendees as $member) {
                $roll = fake()->numberBetween(1, 100);
                $entryCoverage = 0.0;
                $entryCoveredBy = EntryCoverageSource::None;
                $compReasonId = null;

                if ($roll <= 10 && $compReasons->isNotEmpty()) {
                    $entryCoverage = (float) $event->entry_fee;
                    $entryCoveredBy = EntryCoverageSource::EventComp;
                    $compReasonId = $compReasons->random()->id;
                } elseif ($roll <= 30) {
                    $entryCoverage = min(25.0, (float) $event->entry_fee);
                    $entryCoveredBy = EntryCoverageSource::RegularSubscription;
                }

                $poolCoverage = 0.0;
                $poolCoveredBy = PoolCoverageSource::None;
                if ((float) $event->pool_fee > 0 && fake()->boolean(25)) {
                    $poolCoverage = (float) $event->pool_fee;
                    $poolCoveredBy = PoolCoverageSource::PoolSubscription;
                }

                $amountPaid = ((float) $event->entry_fee - $entryCoverage) + ((float) $event->pool_fee - $poolCoverage);

                $attendance = Attendance::create([
                    'member_id' => $member->id,
                    'event_id' => $event->id,
                    'checked_in_by' => $staffIds->random(),
                    'checked_in_at' => $event->starts_at?->copy()->addMinutes(fake()->numberBetween(0, 240)) ?? $event->event_date,
                    'entry_fee' => $event->entry_fee,
                    'entry_coverage' => $entryCoverage,
                    'entry_covered_by' => $entryCoveredBy,
                    'comp_reason_id' => $compReasonId,
                    'pool_fee' => $event->pool_fee,
                    'pool_coverage' => $poolCoverage,
                    'pool_covered_by' => $poolCoveredBy,
                    'voucher_coverage' => 0,
                    'amount_paid' => $amountPaid,
                    'payment_method' => $amountPaid > 0 ? fake()->randomElement(['cash', 'venmo']) : null,
                ]);

                // A light sprinkling of Event Add-Ons, so attendance_add_ons
                // isn't empty either.
                if ($addOns->isNotEmpty() && fake()->boolean(5)) {
                    $addOn = $addOns->random();
                    AttendanceAddOn::create([
                        'attendance_id' => $attendance->id,
                        'add_on_id' => $addOn->id,
                        'name' => $addOn->name,
                        'price' => $addOn->price,
                    ]);
                }
            }
        }
    }

    private function seedSubscriptions(Collection $members, Collection $users): void
    {
        $eligibleMembers = $members->filter(fn (Member $m) => $m->subscription_eligible)
            ->concat($members->where('category.name', 'Irregular')->random(6))
            ->unique('id');

        foreach ($eligibleMembers as $member) {
            foreach (range(2, 0) as $monthsAgo) {
                if (fake()->boolean(60)) {
                    Subscription::create([
                        'member_id' => $member->id,
                        'plan_type' => 'regular',
                        'covered_month' => today()->subMonths($monthsAgo)->startOfMonth(),
                        'amount_paid' => 60,
                        'paid_on' => today()->subMonths($monthsAgo)->startOfMonth()->addDays(2),
                        'recorded_by' => $users->random()->id,
                        'payment_method' => fake()->randomElement(['cash', 'venmo']),
                    ]);
                }
            }
        }
    }

    private function seedVouchers(Collection $members, Collection $users): void
    {
        $recipients = $members->where('category.name', 'Irregular')->random(6);

        foreach ($recipients as $member) {
            Voucher::create([
                'member_id' => $member->id,
                'amount' => fake()->randomElement([15, 25, 50]),
                'reason' => fake()->randomElement(['Presenter thank-you', 'Volunteered at the door', 'Comp reward — worked the coat check']),
                'recorded_by' => $users->random()->id,
            ]);

            if (fake()->boolean(40)) {
                Voucher::create([
                    'member_id' => $member->id,
                    'amount' => -10,
                    'reason' => 'Partial redemption at check-in',
                    'recorded_by' => $users->random()->id,
                ]);
            }
        }
    }

    private function seedCompRequests(Collection $events, Collection $members, Collection $users, Collection $compReasons): void
    {
        $showrunnerEvent = $events->firstWhere('showrunner_id', '!=', null);
        if (! $showrunnerEvent || $compReasons->isEmpty()) {
            return;
        }

        $admin = $users->firstWhere('role', Role::Admin) ?? $users->first();
        $targets = $members->where('category.name', 'Irregular')->random(3)->values();

        CompRequest::create([
            'event_id' => $showrunnerEvent->id,
            'member_id' => $targets[0]->id,
            'comp_reason_id' => $compReasons->random()->id,
            'requested_by' => $admin->id,
            'notes' => 'Worked the door all night',
            'status' => CompRequestStatus::Pending,
        ]);

        CompRequest::create([
            'event_id' => $showrunnerEvent->id,
            'member_id' => $targets[1]->id,
            'comp_reason_id' => null,
            'requested_reason_text' => 'Covered the coat check last-minute',
            'requested_by' => $admin->id,
            'status' => CompRequestStatus::Pending,
        ]);

        CompRequest::create([
            'event_id' => $showrunnerEvent->id,
            'member_id' => $targets[2]->id,
            'comp_reason_id' => $compReasons->random()->id,
            'requested_by' => $admin->id,
            'status' => CompRequestStatus::Rejected,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDay(),
            'review_notes' => 'Not on the confirmed volunteer list',
        ]);
    }

    private function seedRegistersAndShifts(Collection $users): void
    {
        $door = $users->firstWhere('role', Role::Door) ?? $users->first();

        $frontDoor = Register::create(['name' => 'Front Door', 'description' => 'Main entrance cashbox', 'sort_order' => 0]);
        Register::create(['name' => 'Back Bar', 'description' => 'Secondary station', 'sort_order' => 1]);

        // A closed shift with a plausible reconciliation trail.
        RegisterShift::create([
            'register_id' => $frontDoor->id,
            'opened_by' => $door->id,
            'opening_count' => 100,
            'closed_by' => $door->id,
            'closed_at' => today()->subWeek()->setTime(23, 30),
            'closing_count' => 480,
            'notes' => 'Closed clean, no variance.',
        ]);

        // An open shift, so the check-in page's live register-box summary
        // has something to show.
        RegisterShift::create([
            'register_id' => $frontDoor->id,
            'opened_by' => $door->id,
            'opening_count' => 100,
        ]);
    }
}
