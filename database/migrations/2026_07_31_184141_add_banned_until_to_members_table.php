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
        // Null = permanent ban, today's existing meaning, unchanged. A date
        // makes it a time-boxed suspension instead -- the same is_banned
        // Block outcome while active, but auto-expiring rather than needing
        // someone to remember to manually un-ban. Distinct from
        // ban_exceptions (a one-time admit for one event without lifting
        // the ban at all). See Member::isCurrentlyBanned() and
        // docs/BLUEPRINT.md.
        Schema::table('members', function (Blueprint $table) {
            $table->date('banned_until')->nullable()->after('ban_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('banned_until');
        });
    }
};
