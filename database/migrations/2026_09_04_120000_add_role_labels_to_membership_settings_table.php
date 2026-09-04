<?php

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
        // Per-role display-label overrides, keyed by Role::value (e.g.
        // 'showrunner' => 'Dungeon Monitor') -- lets a club use its own
        // vernacular for a role without touching the underlying enum case,
        // DB value, gates, or any other code that keys off it. Null/missing
        // keys fall back to Role::getLabel()'s generic default. A JSON
        // column rather than one column per role since the whole point is a
        // single small settings section, same "editable settings data"
        // philosophy as everywhere else -- see App\Filament\Admin\Pages\RoleLabels.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->json('role_labels')->nullable()->after('org_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('role_labels');
        });
    }
};
