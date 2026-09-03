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
        // Showrunner sits below Door — a narrow, per-event capability (submit
        // comp requests for the one event they're assigned to), not a rank
        // that inherits Door's check-in/payment abilities. See
        // docs/BLUEPRINT.md "Showrunners".
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['showrunner', 'door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }
};
