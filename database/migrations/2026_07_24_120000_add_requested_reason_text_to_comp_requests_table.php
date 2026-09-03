<?php

use App\Models\CompReason;
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
        // Lets a Showrunner submit a comp request with a freeform reason
        // instead of picking an existing comp_reasons row — comp_reason_id
        // is now nullable to allow this (mutually exclusive with
        // requested_reason_text at the application layer, not a DB
        // constraint). Admin+ resolves it into a real CompReason (existing
        // or newly created) at approval time — see CompRequestsRelationManager.
        // See docs/BLUEPRINT.md "Still open".
        Schema::table('comp_requests', function (Blueprint $table) {
            $table->foreignIdFor(CompReason::class)->nullable()->change();
            $table->string('requested_reason_text', 255)->nullable()->after('comp_reason_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comp_requests', function (Blueprint $table) {
            $table->dropColumn('requested_reason_text');
            $table->foreignIdFor(CompReason::class)->nullable(false)->change();
        });
    }
};
