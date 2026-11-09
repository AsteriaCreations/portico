<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff scheduling: who actually worked each shift. A scheduled spot is
     * marked worked when the person signs in during the shift, or by a
     * Manager+ afterwards (who can also add someone who covered without
     * being scheduled). A role can be left out of the monthly shift
     * requirement (e.g. Host), which defaults to 3 a month; 0 turns it off.
     */
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dateTime('worked_at')->nullable()->after('assigned_by');
            $table->enum('worked_source', ['sign_in', 'manager'])->nullable()->after('worked_at');
            $table->foreignId('worked_recorded_by')->nullable()->after('worked_source')->constrained('users')->nullOnDelete();
        });

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->boolean('counts_toward_requirement')->default(true)->after('open_to_all');
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('staff_monthly_shift_requirement')->default(3)->after('staff_handover_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('staff_monthly_shift_requirement');
        });

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->dropColumn('counts_toward_requirement');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worked_recorded_by');
            $table->dropColumn(['worked_at', 'worked_source']);
        });
    }
};
