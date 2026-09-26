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
        // When the club sent a newly registered guest its follow-up (welcome
        // email, waiver, membership info -- whatever the club sends), and which
        // staff account marked it. Null means not sent yet. Set from the
        // Members list's "Mark follow-up sent" bulk action.
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('guest_followup_sent_at')->nullable()->after('sponsor_id');
            $table->foreignIdFor(User::class, 'guest_followup_sent_by')->nullable()->after('guest_followup_sent_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_followup_sent_by');
            $table->dropColumn('guest_followup_sent_at');
        });
    }
};
