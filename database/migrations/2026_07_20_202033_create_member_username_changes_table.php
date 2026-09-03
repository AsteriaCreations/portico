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
        // Append-only audit trail for username renames — who changed it, from
        // what, to what, when. Edit access stays Manager+, same as today;
        // this log is the accountability mechanism, not a tighter gate.
        // Written by App\Observers\MemberObserver, not by any Filament form
        // directly — same shape as member_status_changes.
        Schema::create('member_username_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->string('old_username', 60);
            $table->string('new_username', 60);
            $table->foreignIdFor(User::class, 'changed_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_username_changes');
    }
};
