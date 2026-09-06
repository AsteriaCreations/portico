<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Which member fields the desk (and every other member picker in the
        // app) can search on -- a list of field keys, e.g.
        // ['username', 'name', 'member_number']. Null/missing means
        // username-only, which is how the desk actually works day to day. A
        // JSON column rather than one boolean per field, same "editable
        // settings data, single small section" philosophy as role_labels --
        // see Member::searchableColumns() and App\Filament\Admin\Pages\MembershipSettings.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->json('member_search_fields')->nullable()->after('role_labels');
        });

        // Make the default explicit on the existing row rather than leaning
        // on the null-coalesce in code -- so the Membership Settings page
        // renders "Username" already checked, matching how search actually
        // behaves. (Unlike role_labels, where null genuinely means "no
        // overrides", here null and ['username'] are the same thing.)
        DB::table('membership_settings')
            ->whereNull('member_search_fields')
            ->update(['member_search_fields' => json_encode(['username'])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('member_search_fields');
        });
    }
};
