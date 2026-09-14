<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every add-on was offered at every event before add_on_event existed,
     * so upgrading must not silently pull Sleepover/room-rental off events
     * already using them -- attach every active, non-subscribable add-on to
     * every event that already exists as of this migration. Deliberately
     * one-time: an event created after this runs starts with none attached,
     * and a Manager/Admin picks per event from here on (EventForm's new
     * add_on_ids field).
     */
    public function up(): void
    {
        $addOnIds = DB::table('add_ons')->where('active', true)->where('subscribable', false)->pluck('id');
        $eventIds = DB::table('events')->pluck('id');

        if ($addOnIds->isEmpty() || $eventIds->isEmpty()) {
            return;
        }

        $rows = [];
        foreach ($eventIds as $eventId) {
            foreach ($addOnIds as $addOnId) {
                $rows[] = ['add_on_id' => $addOnId, 'event_id' => $eventId];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('add_on_event')->insertOrIgnore($chunk);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('add_on_event')->truncate();
    }
};
