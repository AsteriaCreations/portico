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
        // Lets an Admin actually fire scripts/deploy.ps1 from
        // App\Filament\Admin\Pages\UpstreamUpdates instead of RDP'ing in --
        // see App\Services\DeployTrigger for why this only ever asks Windows
        // Task Scheduler to run the script (deploy.ps1 stops the very web
        // server serving the request, so it can never run inline). Off and
        // unconfigured by default, same reasoning as upstream_check_enabled:
        // this is brand-new and high-risk, so there is no existing behavior
        // to preserve, and an unconfigured fork has no task to run anyway.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('deploy_trigger_enabled')->default(false)->after('upstream_branch');
            $table->string('deploy_task_name')->nullable()->after('deploy_trigger_enabled');
        });

        // No backfill needed -- false/null is exactly "unconfigured", the
        // right state for an upgrading install that never had this feature.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['deploy_trigger_enabled', 'deploy_task_name']);
        });
    }
};
