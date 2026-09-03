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
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('ended_notification_sent_at')->nullable()->after('ends_at');
        });

        // Backfilled to now() for every existing event so this feature
        // doesn't retroactively fire the moment it ships -- only events that
        // end after this migration runs are eligible for the notification.
        DB::table('events')->whereNull('ended_notification_sent_at')->update([
            'ended_notification_sent_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('ended_notification_sent_at');
        });
    }
};
