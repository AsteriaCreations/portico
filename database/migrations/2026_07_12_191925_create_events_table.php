<?php

use App\Models\EventType;
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
        // No unique constraint on event_date — concurrent same-day events are allowed.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->date('event_date');
            $table->string('name', 80)->nullable();
            $table->foreignIdFor(EventType::class)->nullable()->constrained();
            $table->decimal('entry_fee', 8, 2)->default(0);
            $table->decimal('pool_fee', 8, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('event_date', 'ix_events_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
