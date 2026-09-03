<?php

use App\Models\Attendance;
use App\Models\CompReason;
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
        // A showrunner's nomination for the comp list — deliberately NOT an
        // Attendance row, since that would already count against capacity
        // and appear on the roster before anyone reviewed it. Approving
        // creates the real Attendance row (attendance_id links back to it);
        // rejecting just closes the request out. See
        // docs/BLUEPRINT.md "Showrunners".
        Schema::create('comp_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(CompReason::class)->constrained();
            $table->foreignIdFor(User::class, 'requested_by')->constrained('users');
            $table->string('notes', 255)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignIdFor(User::class, 'reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 255)->nullable();
            $table->foreignIdFor(Attendance::class)->nullable()->constrained('attendance');
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comp_requests');
    }
};
