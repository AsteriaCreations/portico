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
        // A reason Door staff may pick when comping an entry at the desk
        // (e.g. "Brought donation items"), without a Manager present. Off by
        // default so upgrading never widens who can comp; only Admin+ turns
        // it on (CompReasonObserver). Manager+ can still comp with any
        // active reason. See the grant-event-comp gate.
        Schema::table('comp_reasons', function (Blueprint $table) {
            $table->boolean('door_allowed')->default(false)->after('grants_voucher_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comp_reasons', function (Blueprint $table) {
            $table->dropColumn('door_allowed');
        });
    }
};
