<?php

use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\ShiftSlot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticks on role task lists (role_task_completions): a scheduled role's
     * task once per shift (shift_slot_id, with on_shift false when the
     * person ticking wasn't on it), an unscheduled role's once per schedule
     * week (week_start).
     *
     * Then the Cleaning Checklist folds in: its tasks become the standard
     * list of a "Cleaning Crew" shift role that isn't scheduled, their
     * completions move with them, and everyone with the Cleaning Crew
     * capability gets the role. Its tables and the capability system (which
     * only ever had Cleaning Crew) are dropped. Task lists live under Staff
     * scheduling, so a club using the checklist gets that turned on: an
     * upgrade never takes away a feature in use.
     */
    public function up(): void
    {
        Schema::create('role_task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(RoleTask::class)->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignIdFor(ShiftSlot::class)->nullable()->constrained()->nullOnDelete();
            $table->date('week_start')->nullable();
            $table->boolean('on_shift')->nullable();
            $table->timestamp('completed_at');
            $table->unique(['role_task_id', 'shift_slot_id']);
            $table->unique(['role_task_id', 'week_start']);
            $table->index(['user_id', 'completed_at']);
        });

        $this->moveCleaningChecklist();

        Schema::dropIfExists('cleaning_task_completions');
        Schema::dropIfExists('cleaning_tasks');
        Schema::dropIfExists('user_capabilities');
    }

    private function moveCleaningChecklist(): void
    {
        $tasks = DB::table('cleaning_tasks')->orderBy('id')->get();
        $crew = DB::table('user_capabilities')->where('capability', 'cleaning_crew')->pluck('user_id');

        if ($tasks->isEmpty() && $crew->isEmpty()) {
            return;
        }

        $now = now();
        $roleId = DB::table('shift_roles')->where('name', 'Cleaning Crew')->value('id')
            ?? DB::table('shift_roles')->insertGetId([
                'name' => 'Cleaning Crew',
                'description' => 'The weekly cleaning list.',
                'open_to_all' => false,
                'scheduled' => false,
                'counts_toward_requirement' => false,
                'sort_order' => 100,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('shift_roles')->where('id', $roleId)->update(['scheduled' => false]);

        foreach ($tasks as $task) {
            $taskId = DB::table('role_tasks')->insertGetId([
                'shift_role_id' => $roleId,
                'name' => $task->name,
                'description' => $task->description,
                'status' => 'standard',
                'sort_order' => $task->sort_order,
                'active' => $task->active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (DB::table('cleaning_task_completions')->where('cleaning_task_id', $task->id)->get() as $completion) {
                DB::table('role_task_completions')->insert([
                    'role_task_id' => $taskId,
                    'user_id' => $completion->completed_by,
                    'week_start' => substr((string) $completion->for_week_start, 0, 10),
                    'on_shift' => null,
                    'completed_at' => $completion->created_at ?? $completion->for_week_start,
                ]);
            }
        }

        DB::table('shift_role_user')->insertOrIgnore($crew->unique()->map(fn ($userId): array => ['shift_role_id' => $roleId, 'user_id' => $userId])->values()->all());

        DB::table('membership_settings')->update(['staff_scheduling_enabled' => true]);
        MembershipSetting::forgetCurrent();
    }

    /**
     * Restores the old tables empty: what moved stays on the task lists.
     */
    public function down(): void
    {
        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 50);
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'capability']);
        });

        Schema::create('cleaning_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });

        Schema::create('cleaning_task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('completed_by')->constrained('users');
            $table->date('for_week_start');
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['cleaning_task_id', 'for_week_start']);
        });

        Schema::dropIfExists('role_task_completions');
    }
};
