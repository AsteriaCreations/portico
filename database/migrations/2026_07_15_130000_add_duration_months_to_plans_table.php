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
        // A "3-month Regular subscription bundle" is just another plans row: same
        // code, duration_months = 3, its own bulk price. Every existing row
        // becomes duration 1 (today's monthly rate) via the default — the
        // per-visit entry credit always comes from the duration-1 row
        // regardless of what duration a member actually purchased under
        // (see Plan::currentFor()'s default parameter and
        // App\Services\SubscriptionBundleService).
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_months')->default(1)->after('code');
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
