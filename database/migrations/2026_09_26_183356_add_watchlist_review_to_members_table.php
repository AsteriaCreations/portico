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
        // watchlist_review_on: when an Owner should decide whether a
        // watchlisted member comes off -- null keeps them on indefinitely.
        // watchlist_probation_start: set when an Owner removes someone with
        // probation; the end is derived from Membership Settings'
        // watchlist_probation_days (Member::isOnWatchlistProbation()), same
        // "derived, never stored" shape as probation_override_start.
        Schema::table('members', function (Blueprint $table) {
            $table->date('watchlist_review_on')->nullable()->after('watchlist_reason');
            $table->date('watchlist_probation_start')->nullable()->after('watchlist_review_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['watchlist_review_on', 'watchlist_probation_start']);
        });
    }
};
