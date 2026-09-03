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
        // Tracks the last success/failure of a Windows-Task-Scheduler-driven
        // artisan command (backup:database, vouchers:grant-comp-rewards) —
        // this app has no Laravel scheduler, so nothing else records whether
        // one silently stopped running. See the Dashboard's ScheduledJobsWidget.
        Schema::create('command_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 60)->unique();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_message', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('command_runs');
    }
};
