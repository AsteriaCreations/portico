<?php

use App\Models\AddOn;
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
        // One NOT NULL FK for every plan/subscription target -- Entry
        // included, via the protected 'entry' add_ons row created just
        // above. See that migration's own comment for why a nullable
        // "no target" case was rejected.
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignIdFor(AddOn::class)->after('id')->constrained();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignIdFor(AddOn::class)->after('member_id')->constrained();
            $table->unique(['member_id', 'add_on_id', 'covered_month']);
            $table->index(['member_id', 'add_on_id', 'covered_month'], 'ix_subs_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['member_id', 'add_on_id', 'covered_month']);
            $table->dropIndex('ix_subs_lookup');
            $table->dropConstrainedForeignIdFor(AddOn::class);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(AddOn::class);
        });
    }
};
