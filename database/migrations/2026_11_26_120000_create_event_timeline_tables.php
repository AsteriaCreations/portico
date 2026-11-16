<?php

use App\Models\Event;
use App\Models\EventTimelineCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An event's run of show: timed items the showrunner (or Admin+) lays
     * out between the event's starts_at and ends_at. Each item sits in a
     * category; a public category is for attendees (printed sheet, display
     * screen), any other is seen by staff at or above its min_role. A plan,
     * not a ledger: items are edited and deleted freely.
     */
    public function up(): void
    {
        Schema::create('event_timeline_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 7);
            $table->boolean('is_public')->default(false);
            $table->enum('min_role', ['showrunner', 'volunteer', 'dm', 'door', 'manager', 'admin', 'owner'])->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_timeline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(EventTimelineCategory::class)->constrained()->restrictOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->index(['event_id', 'starts_at']);
        });

        $now = now();
        DB::table('event_timeline_categories')->insert([
            ['name' => 'Public', 'color' => '#2563eb', 'is_public' => true, 'min_role' => null, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Event staff', 'color' => '#d97706', 'is_public' => false, 'min_role' => 'volunteer', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('event_timelines_enabled')->default(false)->after('staff_scheduling_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('event_timelines_enabled');
        });

        Schema::dropIfExists('event_timeline_items');
        Schema::dropIfExists('event_timeline_categories');
    }
};
