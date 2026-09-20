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
        // The UI language for this installation. Nullable on purpose: null
        // means "use config('app.locale')", so upgrading changes nothing.
        // See App\Http\Middleware\SetLocale.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
