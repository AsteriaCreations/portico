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
        // Append-only "Report a problem" snapshots from the Check-In Desk,
        // written only by App\Services\IssueReportService. snapshot holds the
        // desk's state with every personal detail left out: the member is a
        // keyed pseudonym, never an id or a name, so a copied snapshot can be
        // shared for troubleshooting.
        Schema::create('issue_reports', function (Blueprint $table) {
            $table->id();
            $table->string('page', 40);
            $table->text('note');
            $table->json('snapshot');
            $table->foreignIdFor(User::class, 'reported_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_reports');
    }
};
