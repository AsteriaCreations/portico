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
        // Which flat, non-subscribable add-ons (room rental, sleepover, …)
        // an event actually offers -- before this table existed, every such
        // add-on was checkable at every event's check-in, with only a
        // building-wide nightly cap (max_per_night) limiting anything. Plain
        // association, not an audit ledger, so no created_by/reason -- who
        // may edit it is enforced by EventPolicy::update() (Admin+) on
        // EventForm's own field, the same bar as every other event property,
        // not by this table's shape. See create_member_skill_table for the
        // same plain-pivot precedent. Subscribable add-ons (Pool) never
        // participate here -- their per-event availability is already
        // governed by event.pool_fee.
        Schema::create('add_on_event', function (Blueprint $table) {
            $table->foreignId('add_on_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->primary(['add_on_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('add_on_event');
    }
};
