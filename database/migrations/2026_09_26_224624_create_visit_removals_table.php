<?php

use App\Models\Event;
use App\Models\Member;
use App\Models\RegisterShift;
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
        // Append-only record of each removed *paid* visit -- the attendance
        // row itself is gone, so this snapshots what it was. Written only by
        // App\Services\VisitRemovalService. after_shift_closed marks a removal
        // an Owner made once the visit's register shift had already closed:
        // RegisterShiftService adds those back so a closed shift's expected
        // cash never changes after the fact.
        Schema::create('visit_removals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(Event::class)->constrained();
            $table->foreignIdFor(RegisterShift::class)->nullable()->constrained();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('amount_paid', 8, 2);
            $table->timestamp('checked_in_at')->nullable();
            $table->boolean('after_shift_closed')->default(false);
            $table->string('reason', 255);
            $table->foreignIdFor(User::class, 'removed_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_removals');
    }
};
