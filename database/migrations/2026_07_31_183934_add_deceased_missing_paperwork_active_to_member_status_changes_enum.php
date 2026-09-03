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
        Schema::table('member_status_changes', function (Blueprint $table) {
            $table->enum('status', ['banned', 'watchlist', 'deceased', 'missing_paperwork', 'active'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_status_changes', function (Blueprint $table) {
            $table->enum('status', ['banned', 'watchlist'])->change();
        });
    }
};
