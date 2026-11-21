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
        // Members in a category with this on may pay entry with a one-time
        // payment method (payment_methods.one_time_only) on every visit --
        // see Member::isOneTimeMethodLocked(). Off by default, so the limit
        // still applies everywhere after an update.
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('one_time_payment_exempt')->default(false)->after('is_comped');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('one_time_payment_exempt');
        });
    }
};
