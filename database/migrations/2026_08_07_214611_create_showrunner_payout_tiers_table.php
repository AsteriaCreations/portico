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
        Schema::create('showrunner_payout_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_headcount');
            $table->unsignedInteger('max_headcount')->nullable();
            $table->enum('payout_type', ['voucher', 'percentage']);
            $table->decimal('payout_value', 8, 2);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('showrunner_payout_tiers');
    }
};
