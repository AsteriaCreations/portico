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
        // The member responsible for a Guest-category member they brought.
        // Self-referential and nullable — every non-Guest member leaves this
        // null. See docs/BLUEPRINT.md "Guests".
        Schema::table('members', function (Blueprint $table) {
            $table->foreignIdFor(Member::class, 'sponsor_id')->nullable()->after('category_id')->constrained('members');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_id');
        });
    }
};
