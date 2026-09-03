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
        // DM sits between Volunteer and Door — a further level of trusted
        // volunteer who also sees full behavior-note text (not who wrote
        // it) on Active Patrons, but still short of Door's
        // check-in/payment/prospective-capture abilities. See
        // docs/BLUEPRINT.md "Roles & permissions".
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['showrunner', 'volunteer', 'dm', 'door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['showrunner', 'volunteer', 'door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }
};
