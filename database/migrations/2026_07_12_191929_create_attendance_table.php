<?php

use App\Models\Event;
use App\Models\Member;
use App\Models\User;
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
        // Entry is priced and recorded here as its own snapshot line; every
        // other chargeable (pool, and any other subscribable add-on) is
        // recorded as an attendance_add_ons row instead (see that table) —
        // one shared shape for "a charge that can be comped/subscribed/
        // day-passed," rather than a second hardcoded pair of columns here.
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(Event::class)->constrained();
            $table->foreignIdFor(User::class, 'checked_in_by')->nullable()->constrained('users');
            $table->timestamp('checked_in_at')->nullable();
            // ENTRY component snapshot
            $table->decimal('entry_fee', 8, 2)->default(0);
            $table->decimal('entry_coverage', 8, 2)->default(0);
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription'])->default('none');
            // settlement
            $table->decimal('amount_paid', 8, 2)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->string('on_behalf_note', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            // event_id/member_id are already indexed by their FK constraints above,
            // satisfying ix_attendance_event/ix_attendance_member without duplicating them.
            $table->unique(['member_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
