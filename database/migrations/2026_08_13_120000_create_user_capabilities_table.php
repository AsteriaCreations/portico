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
        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Plain string, not enum() -- see App\Enums\Capability for why.
            $table->string('capability', 50);
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'capability']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
    }
};
