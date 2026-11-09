<?php

use App\Models\RoleTask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Up or down votes on a proposed role task, from people who can work
     * that role, one per person (changeable while it's proposed). Advice
     * for the Manager+ who approves or declines it.
     */
    public function up(): void
    {
        Schema::create('role_task_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(RoleTask::class)->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('vote');
            $table->timestamps();
            $table->unique(['role_task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_task_votes');
    }
};
