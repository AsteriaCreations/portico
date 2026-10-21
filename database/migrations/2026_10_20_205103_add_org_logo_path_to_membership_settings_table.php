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
        // The club's own logo, a path on the public disk. Null keeps showing
        // org_name as text, as before.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('org_logo_path')->nullable()->after('org_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('org_logo_path');
        });
    }
};
