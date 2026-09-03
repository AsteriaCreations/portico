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
        Schema::table('members', function (Blueprint $table) {
            // on_probation becomes a computed value (Member::isOnProbation())
            // derived from this date (or date_vetted if unset) plus a
            // configurable period — same "derived, never stored" pattern as
            // subscription eligibility/under-21, rather than a manually-toggled boolean.
            // See docs/BLUEPRINT.md "Still open".
            $table->date('probation_override_start')->nullable()->after('date_vetted');
            $table->dropColumn('on_probation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('on_probation')->default(false);
            $table->dropColumn('probation_override_start');
        });
    }
};
