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
        // Append-only record of each Owner decision on a watchlist entry --
        // remove, push the review date out, or keep indefinitely. Written only
        // by EditMember's review action; the on/off flip itself is still
        // logged in member_status_changes by MemberObserver.
        Schema::create('watchlist_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->enum('decision', ['removed', 'extended', 'kept_indefinitely']);
            $table->date('previous_review_on')->nullable();
            $table->date('new_review_on')->nullable();
            $table->boolean('probation_started')->default(false);
            $table->string('notes', 255)->nullable();
            $table->foreignIdFor(User::class, 'decided_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watchlist_reviews');
    }
};
