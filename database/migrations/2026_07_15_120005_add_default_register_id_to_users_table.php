<?php

use App\Models\Register;
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
        // Sticky "last register worked" per user, so the check-in page's
        // register picker doesn't ask again every time it's opened. Not
        // unique — many staff can share the same default register.
        // nullOnDelete: a register being retired shouldn't be blocked by a
        // stale user preference.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignIdFor(Register::class, 'default_register_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_register_id');
        });
    }
};
