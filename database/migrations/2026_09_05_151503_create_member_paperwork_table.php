<?php

use App\Models\Member;
use App\Models\PaperworkType;
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
        // Append-only log of every time a member signed a given paperwork
        // type -- an annually-renewed waiver gets a new row each year, and
        // the latest signed_on is what Member::hasValidPaperwork() reads.
        // Never edited after creation, same shape as attendance_add_ons /
        // member_status_changes: created_at only, no updated_at.
        Schema::create('member_paperwork', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(PaperworkType::class)->constrained()->cascadeOnDelete();
            $table->date('signed_on');
            // null for a console/import-driven record (no authenticated user
            // to attribute it to), same convention as the legacy roster
            // import elsewhere.
            $table->foreignIdFor(User::class, 'recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['member_id', 'paperwork_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_paperwork');
    }
};
