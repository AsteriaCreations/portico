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
        // Designates the member responsible for running this event — distinct
        // from created_by (a users row / staff account). See
        // docs/BLUEPRINT.md "Showrunners".
        Schema::table('events', function (Blueprint $table) {
            $table->foreignIdFor(Member::class, 'showrunner_id')->nullable()->after('door_prepay_enabled')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('showrunner_id');
        });
    }
};
