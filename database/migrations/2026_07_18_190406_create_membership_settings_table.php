<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A single row, not a history — mirrors config/membership.php's shape
        // exactly, admin-editable via App\Filament\Admin\Pages\MembershipSettings
        // instead of requiring an env change + redeploy. See
        // App\Models\MembershipSetting::current().
        Schema::create('membership_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('subscription_eligibility_threshold');
            $table->unsignedInteger('probation_period_days');
            $table->unsignedInteger('venue_capacity')->nullable();
            $table->decimal('default_opening_float', 8, 2)->nullable();
            $table->unsignedInteger('event_window_buffer_minutes');
            $table->timestamps();
        });

        // Seeded from the current config/membership.php values (env-driven),
        // so both a fresh `migrate:fresh` and a plain `migrate` on an
        // existing install end up with identical behavior to before this
        // table existed — no separate seeder step required.
        DB::table('membership_settings')->insert([
            'subscription_eligibility_threshold' => (int) config('membership.subscription_eligibility_threshold'),
            'probation_period_days' => (int) config('membership.probation_period_days'),
            'venue_capacity' => config('membership.venue_capacity'),
            'default_opening_float' => config('membership.default_opening_float'),
            'event_window_buffer_minutes' => (int) config('membership.event_window_buffer_minutes'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_settings');
    }
};
