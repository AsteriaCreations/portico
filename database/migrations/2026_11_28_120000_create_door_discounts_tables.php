<?php

use App\Models\Attendance;
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
        // Fixed amounts off entry the desk can apply (e.g. "Food donation",
        // $5) -- see PricingService::entryDiscountCents(). An empty or
        // all-inactive list hides the desk field, so there's no flag.
        Schema::create('door_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('description')->nullable();
            $table->decimal('amount', 8, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });

        // Append-only: the discount a visit got, name and applied amount
        // snapshotted so a later rename or price change never rewrites it.
        // `amount` is what actually came off -- less than the discount's own
        // amount when less entry than that was owed. One per visit.
        Schema::create('attendance_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Attendance::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('door_discount_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->decimal('amount', 8, 2);
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_discounts');
        Schema::dropIfExists('door_discounts');
    }
};
