<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff scheduling: for a night someone isn't working, whether they'll
     * be there anyway (off) or not (out). Given on My availability alongside
     * their shift answers, per night (the date events start on), and shown
     * on the schedule grid for anyone not scheduled that night.
     */
    public function up(): void
    {
        Schema::create('shift_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('night');
            $table->enum('status', ['off', 'out']);
            $table->timestamps();
            $table->unique(['user_id', 'night']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_presences');
    }
};
