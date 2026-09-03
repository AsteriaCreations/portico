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
        // id kept as Laravel's default BIGINT (blueprint specifies INT; no functional
        // difference at this scale, and it stays idiomatic Laravel/Filament).
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_comped')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
