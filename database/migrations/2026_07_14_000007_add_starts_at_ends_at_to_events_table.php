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
        // Additive to event_date, which remains the source of truth for age
        // cutoffs, subscription month, and the check-in event picker's "today"
        // filter. These drive display and the comp-reward voucher trigger
        // only. Nullable at the DB level so existing events aren't broken;
        // the form requires them going forward. See
        // docs/BLUEPRINT.md "Prepay events" / comp rewards.
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->after('event_date');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
