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
        // What this subscription covers (Entry, Pool, or any other
        // subscribable add-on) is added by a later migration, once add_ons
        // exists to reference — see
        // add_add_on_id_to_plans_and_subscriptions_table, which also adds
        // this table's real unique/lookup indexes.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Member::class)->constrained();
            $table->date('covered_month'); // first day of the covered month
            $table->decimal('amount_paid', 8, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->foreignIdFor(User::class, 'recorded_by')->nullable()->constrained('users');
            $table->timestamps();
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
