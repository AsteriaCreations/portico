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
        // Lets an Admin see which upstream commits are pending before
        // running an update (docs/DEPLOYMENT.md §7) without any network call
        // per page load -- a scheduled upstream:check command does the git
        // fetch, App\Filament\Admin\Pages\UpstreamUpdates just diffs
        // locally. Opt-in and unconfigured by default (unlike most flags
        // here, which default true to preserve existing behavior) -- a fork
        // must add a git remote by hand first (this app never runs
        // `git remote add` itself), so there is no existing behavior to
        // preserve. See App\Services\UpstreamUpdateChecker.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('upstream_check_enabled')->default(false)->after('behavior_notes_enabled');
            $table->string('upstream_remote')->nullable()->after('upstream_check_enabled');
            $table->string('upstream_branch')->default('main')->after('upstream_remote');
        });

        // No backfill needed -- the column-level defaults above already
        // cover every existing row correctly (false/null/'main' is exactly
        // "unconfigured", the right state for an upgrading install that
        // never had this feature).
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['upstream_check_enabled', 'upstream_remote', 'upstream_branch']);
        });
    }
};
