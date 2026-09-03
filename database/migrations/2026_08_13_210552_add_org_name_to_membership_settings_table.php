<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Nullable, no default -- null means "show config('app.name')", the
        // existing behavior, so upgrading an install never changes what's
        // displayed until an Owner deliberately sets one. Owner-only to
        // change (see the manage-org-name gate in AppServiceProvider): the
        // panel's displayed name is the one club-identity field that isn't
        // a Manager-level operational tunable, so it doesn't share
        // MembershipSettings' usual Manager+ floor.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('org_name')->nullable()->after('event_window_buffer_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('org_name');
        });
    }
};
