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
        // Whether events:depart-ended marks everyone still on site as departed
        // once an event (and its window buffer) has ended. Off by default: a
        // new capability that writes to attendance rows.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('auto_depart_at_event_end')->default(false)->after('reset_desk_event_on_member_change');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('auto_depart_at_event_end');
        });
    }
};
