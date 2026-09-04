<?php

use App\Models\AddOn;
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
        // Append-only, one-time purchase covering a subscribable add-on for
        // exactly one event -- distinct from a subscription (a whole
        // calendar month, subscriptions.covered_month). amount_paid
        // snapshots the add-on's price for that event at time of purchase
        // (AddOn::priceFor()), same "never recompute" principle as
        // attendance's own fee columns. Pool is the only day-passable
        // add-on today. See docs/BLUEPRINT.md "Fee pipeline".
        Schema::create('add_on_day_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(Event::class)->constrained();
            $table->foreignIdFor(AddOn::class)->constrained();
            $table->decimal('amount_paid', 8, 2);
            $table->string('payment_method');
            $table->foreignIdFor(RegisterShift::class)->nullable()->constrained();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->unique(['member_id', 'event_id', 'add_on_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('add_on_day_passes');
    }
};
