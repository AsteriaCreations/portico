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
        // A "3-month Regular subscription bundle" is just another plans row for the
        // same target: duration_months = 3, its own bulk price. Every existing row
        // becomes duration 1 (today's monthly rate) via the default — the
        // per-visit entry credit always comes from the duration-1 row
        // regardless of what duration a member actually purchased under
        // (see Plan::currentFor()'s default parameter and
        // App\Services\SubscriptionBundleService).
        Schema::table('plans', function (Blueprint $table) {
            // AFTER 'credit': the plans table has no 'code' column (it gains
            // 'add_on_id' in a later migration). MySQL/MariaDB errors on an
            // AFTER referencing a missing column; SQLite silently ignores it.
            $table->unsignedSmallInteger('duration_months')->default(1)->after('credit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('duration_months');
        });
    }
};
