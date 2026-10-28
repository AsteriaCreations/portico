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
        // Whether the Check-In Desk shows "Report a problem"
        // (IssueReportService). Off by default: a new capability, so
        // upgrading changes nothing until a club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('issue_reports_enabled')->default(false)->after('staff_login_checkin_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('issue_reports_enabled');
        });
    }
};
