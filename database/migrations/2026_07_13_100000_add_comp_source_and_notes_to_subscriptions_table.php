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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Tags a $0 subscription created via a non-payment path (e.g. the
            // monthly Manager/Owner subscription perk) so that path's live eligibility check
            // can find its own rows without a separate table. NULL for a
            // normal paid subscription.
            $table->string('comp_source', 50)->nullable()->after('recorded_by');
            $table->string('notes', 255)->nullable()->after('comp_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['comp_source', 'notes']);
        });
    }
};
