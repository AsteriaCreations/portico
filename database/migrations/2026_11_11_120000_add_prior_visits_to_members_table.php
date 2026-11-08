<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = 'id, member_number, category_id, is_active, subscription_eligible, on_watchlist, is_banned, banned_until, missing_paperwork, is_deceased, sponsor_id, email_opt_in, probation_override_start, date_vetted, created_at, updated_at';

    /**
     * Visits a member made before the club's records in this app start (an
     * import's pre-computed per-year totals, or a Manager's count). Any at
     * all means they've been before: no first visit, no new-member badge,
     * no probation; they also count toward subscription eligibility. The
     * analyst view gains the column.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedInteger('prior_visits')->default(0)->after('date_vetted');
        });

        $this->recreateAnalystView(self::COLUMNS.', prior_visits');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->recreateAnalystView(self::COLUMNS);

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('prior_visits');
        });
    }

    private function recreateAnalystView(string $columns): void
    {
        DB::statement('DROP VIEW IF EXISTS analyst_members');
        DB::statement("CREATE VIEW analyst_members AS SELECT {$columns} FROM members");
    }
};
