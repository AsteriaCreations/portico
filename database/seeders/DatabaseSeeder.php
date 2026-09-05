<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Outside local/testing, refuse to ship the guessable dev admin
        // credential — a real install must supply its own via env. See
        // docs/DEPLOYMENT.md.
        if (app()->environment('local', 'testing')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => Role::Admin,
            ]);
        } else {
            $email = env('ADMIN_EMAIL');
            $password = env('ADMIN_PASSWORD');

            throw_unless($email && $password, RuntimeException::class,
                'Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before seeding a non-local environment.');

            // Built without the factory on purpose: fakerphp/faker is a dev
            // dependency, so a `composer install --no-dev` production install
            // (per docs/DEPLOYMENT.md) has no faker to back
            // UserFactory::definition(). Mirrors the system user below.
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => 'Admin',
                    'password' => $password,
                    'role' => Role::Admin,
                    'active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }

        // Attribution for automated grants (e.g. vouchers:grant-comp-rewards)
        // — active is false so this can never actually log into the panel,
        // it exists purely as a valid recorded_by FK target. See
        // docs/BLUEPRINT.md "Comp list".
        User::updateOrCreate(
            ['email' => 'system@portico.internal'],
            [
                'name' => 'System',
                'role' => Role::Admin,
                'active' => false,
                'password' => Str::random(40),
            ],
        );

        $this->call([
            CategorySeeder::class,
            EventTypeSeeder::class,
            AddOnSeeder::class,
            PlanSeeder::class, // depends on AddOnSeeder (Entry/Pool rows)
            PaperworkTypeSeeder::class, // depends on AddOnSeeder (Pool row, for the Pool Waiver gate)
            CompReasonSeeder::class,
            ShowrunnerPayoutTierSeeder::class,
            InstructorPayRateSeeder::class,
        ]);
    }
}
