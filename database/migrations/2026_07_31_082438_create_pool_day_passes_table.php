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
        // Append-only, one-time purchase covering pool for exactly one
        // event -- distinct from a Pool subscription (a whole calendar
        // month, subscriptions.covered_month). amount_paid snapshots
        // event.pool_fee at time of purchase, same "never recompute"
        // principle as attendance's own fee columns. See
        // docs/BLUEPRINT.md "Still open" (pool pass).
        Schema::create('pool_day_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(Event::class)->constrained();
            $table->decimal('amount_paid', 8, 2);
            $table->string('payment_method');
            $table->foreignIdFor(RegisterShift::class)->nullable()->constrained();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->unique(['member_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pool_day_passes');
    }
};
