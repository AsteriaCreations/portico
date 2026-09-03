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
        // Plain member<->skill association -- not an audit ledger like
        // ban_exceptions/member_status_changes, so no created_by/reason;
        // who may write it is enforced by the assign-member-skills gate
        // (Admin+) on MemberForm's skills field, not by this table's shape.
        Schema::create('member_skill', function (Blueprint $table) {
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['member_id', 'skill_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_skill');
    }
};
