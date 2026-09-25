<?php

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
        // Archiving retires an event without deleting it: attendance,
        // payments and comp history all point here and must keep resolving.
        // Not SoftDeletes, which would hide the event from every relation
        // that reads it. Null means active, so upgrading archives nothing.
        // See Event::archive().
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('notes');
            $table->foreignIdFor(User::class, 'archived_by')->nullable()->after('archived_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn('archived_at');
        });
    }
};
