<?php

use App\Models\Member;
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
        // The member hosting this event — automatically granted free entry
        // when they check in to it (PricingService::price()). Distinct from
        // showrunner_id (submits comp-list nominations for Admin approval)
        // and from Category.is_comped (a permanent property of the member,
        // not tied to one event). See docs/BLUEPRINT.md "Host".
        Schema::table('events', function (Blueprint $table) {
            $table->foreignIdFor(Member::class, 'host_id')->nullable()->after('showrunner_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
        });
    }
};
