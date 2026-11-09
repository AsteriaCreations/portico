<?php

use App\Models\ShiftRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task lists per shift role. shift_roles.scheduled = false is a role
     * that isn't put on event shifts (Cleaning): no availability, staffing
     * counts or schedule, and its tasks reset weekly instead of per shift.
     * task_areas group tasks by where they're done (Bathrooms, Main
     * floor…), each with notes on how that area is handled. A role_tasks
     * row is a standard task, or a proposal staff made that a Manager+
     * approves (standard) or declines.
     */
    public function up(): void
    {
        Schema::table('shift_roles', function (Blueprint $table) {
            $table->boolean('scheduled')->default(true)->after('open_to_all');
        });

        Schema::create('task_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('notes', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('role_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ShiftRole::class)->constrained()->restrictOnDelete();
            $table->foreignId('task_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->enum('status', ['standard', 'proposed', 'declined'])->default('standard');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['shift_role_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_tasks');
        Schema::dropIfExists('task_areas');

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->dropColumn('scheduled');
        });
    }
};
