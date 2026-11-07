<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recreated unchanged after widening the status enum. Widening rebuilds
     * the table on SQLite, which refuses while this view
     * (create_analyst_views) reads it, so it's dropped around the change.
     */
    private const STATUS_VIEW = 'CREATE VIEW analyst_member_status_changes AS SELECT id, member_id, status, value, changed_by, created_at FROM member_status_changes';

    /**
     * A Manager+ flag that stops the desk booking a private rental for this
     * member, with a reason (Manager+ only, like ban_reason). Changes are
     * logged to member_status_changes by MemberObserver, so the status enum
     * widens too.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('rentals_blocked')->default(false)->after('is_deceased');
            $table->string('rentals_blocked_reason')->nullable()->after('rentals_blocked');
        });

        $this->setStatuses(['banned', 'watchlist', 'deceased', 'missing_paperwork', 'active', 'rentals_blocked']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setStatuses(['banned', 'watchlist', 'deceased', 'missing_paperwork', 'active']);

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['rentals_blocked', 'rentals_blocked_reason']);
        });
    }

    /**
     * @param  list<string>  $statuses
     */
    private function setStatuses(array $statuses): void
    {
        DB::statement('DROP VIEW IF EXISTS analyst_member_status_changes');

        Schema::table('member_status_changes', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->change();
        });

        DB::statement(self::STATUS_VIEW);
    }
};
