<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Superseded by the paperwork_types / member_paperwork model -- the
     * previous migration backfilled every non-null value into a "Standard
     * Paperwork" member_paperwork row first.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('paperwork_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->date('paperwork_date')->nullable()->after('subscription_eligible');
        });
    }
};
