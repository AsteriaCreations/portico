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
        // Editable catalog of chargeable extras a member can be charged for
        // at check-in (e.g. "Private room rental", "Sleepover", "Pool") —
        // editable settings data, not a hardcoded list, same shape as
        // comp_reasons/payment_methods. See docs/BLUEPRINT.md
        // "Fee pipeline".
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            // Exactly one 'entry' row (seeded, protected like
            // categories.PROTECTED_NAMES) so plans/subscriptions can
            // reference a single NOT NULL add_on_id FK for every target,
            // Entry included, instead of a nullable "no target" case that
            // would silently break their unique(member_id, add_on_id,
            // covered_month) index -- SQL unique indexes don't treat NULL
            // as colliding with NULL. Entry's own fee logic (event.entry_fee,
            // host bypass, credit) never reads this row's price/pricing_mode
            // -- only the subscription-lookup side is unified through it.
            $table->enum('kind', ['entry', 'addon'])->default('addon');
            // A per_event add-on's price varies by event rather than having
            // one flat catalog price -- Pool is the only one today, priced
            // from events.pool_fee directly (see AddOn::priceFor()). price
            // is nullable because a priced_per_event add-on has no flat
            // default to fall back on.
            $table->boolean('priced_per_event')->default(false);
            $table->decimal('price', 8, 2)->nullable();
            // Whether this add-on participates in PricingService's coverage
            // engine (comp / active subscription / day-pass) at all. A
            // non-subscribable add-on is untouched by any of that -- flat
            // price, always charged in full, never comped or voucher-
            // covered, exactly as before this column existed.
            $table->boolean('subscribable')->default(false);
            $table->string('description', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('add_ons');
    }
};
