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
        // Set once, when a Manager posts the report as a GitHub issue
        // (IssueReportService::postToGitHub()); the snapshot itself never
        // changes.
        Schema::table('issue_reports', function (Blueprint $table) {
            $table->string('posted_url', 255)->nullable()->after('reported_by');
            $table->timestamp('posted_at')->nullable()->after('posted_url');
            $table->foreignIdFor(User::class, 'posted_by')->nullable()->after('posted_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issue_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('posted_by');
            $table->dropColumn(['posted_url', 'posted_at']);
        });
    }
};
