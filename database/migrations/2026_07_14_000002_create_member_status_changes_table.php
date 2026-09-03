<?php

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
        // Append-only audit trail for is_banned/on_watchlist changes — who
        // changed it, to what, when, and why. Edit access to those two
        // fields stays Manager+ same as today; this log is the
        // accountability mechanism, not a tighter gate. Written by
        // App\Observers\MemberObserver, not by any Filament form directly.
        Schema::create('member_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->enum('status', ['banned', 'watchlist']);
            $table->boolean('value');
            $table->string('reason', 255)->nullable();
            $table->foreignIdFor(User::class, 'changed_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_status_changes');
    }
};
