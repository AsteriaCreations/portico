<?php

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff scheduling: a Manager+ recognises someone who worked beyond the
     * monthly shift requirement with a voucher on their linked member
     * record. One row per person per month (append-only), so it's granted
     * once; staff_extra_shift_voucher_amount pre-fills the amount per extra
     * shift, and the Manager can change it.
     */
    public function up(): void
    {
        Schema::create('shift_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->restrictOnDelete();
            $table->date('month');
            $table->unsignedSmallInteger('shifts_worked');
            $table->unsignedSmallInteger('shifts_beyond');
            $table->decimal('amount', 8, 2);
            $table->foreignIdFor(Voucher::class)->constrained()->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'month']);
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->decimal('staff_extra_shift_voucher_amount', 8, 2)->nullable()->after('staff_monthly_shift_requirement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('staff_extra_shift_voucher_amount');
        });

        Schema::dropIfExists('shift_rewards');
    }
};
