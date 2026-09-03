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
        // Append-only: "task X was completed by Y for week Z" is a
        // historical fact, never edited in place. One row per task per
        // calendar week (for_week_start = that week's Monday).
        Schema::create('cleaning_task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('completed_by')->constrained('users');
            $table->date('for_week_start');
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['cleaning_task_id', 'for_week_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_task_completions');
    }
};
