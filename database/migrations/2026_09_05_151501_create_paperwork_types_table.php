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
        // Editable catalog of the signed forms/waivers a club tracks per
        // member (Standard Paperwork, an annually-renewed Pool Waiver, …) --
        // editable settings data, not a hardcoded list, same shape as
        // add_ons / comp_reasons / payment_methods. Seeded by
        // PaperworkTypeSeeder, not here, so a seeded row can resolve
        // AddOn::pool()'s id (seeder ordering guarantees AddOnSeeder ran
        // first). See docs/BLUEPRINT.md.
        Schema::create('paperwork_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('required')->default(true);
            // null = one-time, never expires; 12 = renews annually. A
            // member's latest signed_on + renewal_months must be in the
            // future for their paperwork to count as valid.
            $table->unsignedInteger('renewal_months')->nullable();
            // A member without valid paperwork of this type can't use the
            // linked add-on -- PricingService drops that add-on's line for
            // them and CheckIn blocks a day-pass purchase. Pool, at launch.
            $table->foreignId('gates_add_on_id')->nullable()->constrained('add_ons')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paperwork_types');
    }
};
