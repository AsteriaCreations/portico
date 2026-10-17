<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // When the desk and kiosk stop taking new check-ins for an event.
        // Defaults from ends_at: an event that runs past midnight closes
        // checkin_close_minutes_overnight before it ends, any other
        // checkin_close_minutes_same_day before. Null = open until the
        // event leaves the desk. See Event::defaultCheckInClose().
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('checkin_closes_at')->nullable()->after('ends_at');
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('checkin_close_minutes_overnight')->default(60)->after('event_window_buffer_minutes');
            $table->unsignedSmallInteger('checkin_close_minutes_same_day')->default(30)->after('checkin_close_minutes_overnight');
        });

        // Events not yet over get the default, so ones already scheduled
        // close like newly created ones. Past events stay null: their
        // check-ins are history.
        DB::table('events')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', now())
            ->orderBy('id')
            ->each(function (object $event): void {
                $startsAt = Carbon::parse($event->starts_at);
                $endsAt = Carbon::parse($event->ends_at);
                $closesAt = $endsAt->copy()->subMinutes($endsAt->isSameDay($startsAt) ? 30 : 60)->max($startsAt);

                DB::table('events')->where('id', $event->id)->update(['checkin_closes_at' => $closesAt]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('checkin_closes_at');
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['checkin_close_minutes_overnight', 'checkin_close_minutes_same_day']);
        });
    }
};
