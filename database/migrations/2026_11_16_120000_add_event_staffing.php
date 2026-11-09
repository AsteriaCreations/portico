<?php

use App\Models\EventType;
use App\Models\ShiftRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staffing is set per event, not per event type: each event's standard
     * shifts (Open and Close, or one shift on single-shift nights) carry a
     * kind, and setup / partial-coverage shifts can carry a label. A shift
     * role can be open to everyone on the roster (Setup). The per-event-type
     * templates are dropped; shifts already on events keep their times.
     */
    public function up(): void
    {
        Schema::table('shift_slots', function (Blueprint $table) {
            $table->enum('kind', ['open', 'close', 'single', 'setup', 'extra'])->default('extra')->after('shift_role_id');
            $table->string('label', 40)->nullable()->after('kind');
        });

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->boolean('open_to_all')->default(false)->after('description');
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->json('staff_single_shift_days')->nullable()->after('staff_schedule_week_starts_on');
            $table->unsignedSmallInteger('staff_handover_minutes')->default(30)->after('staff_single_shift_days');
        });

        Schema::dropIfExists('shift_templates');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EventType::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ShiftRole::class)->constrained()->cascadeOnDelete();
            $table->integer('start_offset_minutes')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedTinyInteger('headcount')->default(1);
            $table->timestamps();
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['staff_single_shift_days', 'staff_handover_minutes']);
        });

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->dropColumn('open_to_all');
        });

        Schema::table('shift_slots', function (Blueprint $table) {
            $table->dropColumn(['kind', 'label']);
        });
    }
};
