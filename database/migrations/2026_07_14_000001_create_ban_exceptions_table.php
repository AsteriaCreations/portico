<?php

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
        // A one-time exception admitting a specific banned member to a
        // specific event without lifting the ban itself (e.g. a
        // re-introduction to decorum at a newbie night). Surfaces at the
        // door as a WARN (reusing the existing acknowledge flow), not a
        // silent OK — see docs/BLUEPRINT.md "Still open".
        Schema::create('ban_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(Event::class)->constrained();
            $table->foreignIdFor(User::class, 'granted_by')->constrained('users');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['member_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ban_exceptions');
    }
};
