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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->enum('plan_type', ['regular', 'pool']); // regular covers ENTRY, pool covers POOL
            $table->date('covered_month'); // first day of the covered month
            $table->decimal('amount_paid', 8, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->foreignIdFor(User::class, 'recorded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['member_id', 'plan_type', 'covered_month']);
            $table->index(['member_id', 'plan_type', 'covered_month'], 'ix_subs_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
