<?php

use App\Models\Attendance;
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
        // Append-only account-credit ledger: no stored balance column, a
        // member's available credit is always SUM(amount) for their
        // member_id. Positive rows are issued (Admin only); negative rows are
        // redemptions (any role), which may debit a different member's
        // balance than the one who attended (attendance_id) — see
        // docs/BLUEPRINT.md "Vouchers". No updated_at: rows are
        // never edited, only offset by a new row.
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->decimal('amount', 8, 2);
            $table->string('reason', 255);
            $table->foreignIdFor(Attendance::class)->nullable()->constrained();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            // member_id is already indexed by its FK constraint above,
            // satisfying ix_vouchers_member without duplicating it.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
