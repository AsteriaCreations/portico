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
        // The club's own wording for the Check-In Desk's status bar, one
        // headline and one "what to do" line per situation. Null means the
        // shipped wording, so upgrading changes nothing. Text only: the
        // admission decision itself stays in AdmissionPolicy.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('house_policy_banned_headline', 120)->nullable()->after('watchlist_probation_blocks_guests');
            $table->string('house_policy_banned_instructions', 255)->nullable()->after('house_policy_banned_headline');
            $table->string('house_policy_watchlist_headline', 120)->nullable()->after('house_policy_banned_instructions');
            $table->string('house_policy_watchlist_instructions', 255)->nullable()->after('house_policy_watchlist_headline');
            $table->string('house_policy_under_majority_headline', 120)->nullable()->after('house_policy_watchlist_instructions');
            $table->string('house_policy_under_majority_instructions', 255)->nullable()->after('house_policy_under_majority_headline');
            $table->string('house_policy_under_alcohol_age_headline', 120)->nullable()->after('house_policy_under_majority_instructions');
            $table->string('house_policy_under_alcohol_age_instructions', 255)->nullable()->after('house_policy_under_alcohol_age_headline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn([
                'house_policy_banned_headline',
                'house_policy_banned_instructions',
                'house_policy_watchlist_headline',
                'house_policy_watchlist_instructions',
                'house_policy_under_majority_headline',
                'house_policy_under_majority_instructions',
                'house_policy_under_alcohol_age_headline',
                'house_policy_under_alcohol_age_instructions',
            ]);
        });
    }
};
