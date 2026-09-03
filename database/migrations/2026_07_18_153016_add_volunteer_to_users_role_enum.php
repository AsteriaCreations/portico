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
        // Volunteer sits between Showrunner and Door — trusted enough to
        // record building departures from the Dashboard, but still short of
        // Door's check-in/payment/prospective-capture abilities. See
        // docs/BLUEPRINT.md "Roles".
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['showrunner', 'volunteer', 'door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['showrunner', 'door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }
};
