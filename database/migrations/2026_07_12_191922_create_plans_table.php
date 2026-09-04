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
        // What this plan targets (Entry, Pool, or any other subscribable
        // add-on) is added by a later migration, once add_ons exists to
        // reference — see add_add_on_id_to_plans_and_subscriptions_table.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->decimal('price', 8, 2);
            $table->decimal('credit', 8, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
