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
        // Whether the close-box envelope reminder gives subscription cash an
        // envelope of its own (what it always did) or folds it into the
        // night's envelope. Default true keeps upgrading installs as they were.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('separate_subscription_envelope')->default(true)->after('hold_desk_prepay_cash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('separate_subscription_envelope');
        });
    }
};
