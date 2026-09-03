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
        Schema::table('users', function (Blueprint $table) {
            // Owner sits above Admin — a superset of every other tier, plus
            // (alongside Manager) the one deliberate exception to that
            // hierarchy: granting the monthly subscription perk. See
            // docs/BLUEPRINT.md "Roles & permissions".
            $table->enum('role', ['door', 'manager', 'admin', 'owner'])->default('door')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['door', 'manager', 'admin'])->default('door')->change();
        });
    }
};
