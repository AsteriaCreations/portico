<?php

use App\Models\Category;
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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->integer('member_number')->nullable()->unique();
            $table->string('username', 60)->unique();
            $table->string('preferred_name', 60)->nullable();
            $table->string('first_name', 60)->nullable();
            $table->string('last_name', 60)->nullable();
            $table->string('email', 120)->nullable();
            $table->foreignIdFor(Category::class)->constrained();
            $table->date('date_vetted')->nullable();
            $table->date('dob')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('subscription_eligible')->default(false);
            $table->date('paperwork_date')->nullable();
            // status flags (were cell colours in the spreadsheet)
            $table->boolean('on_watchlist')->default(false);
            $table->string('watchlist_reason', 255)->nullable(); // MANAGER-ONLY
            $table->boolean('is_banned')->default(false);
            $table->string('ban_reason', 255)->nullable(); // MANAGER-ONLY
            $table->boolean('on_probation')->default(false);
            $table->boolean('missing_paperwork')->default(false);
            $table->boolean('is_deceased')->default(false);
            $table->string('hospitality_note', 120)->nullable();
            $table->text('notes')->nullable(); // MANAGER-ONLY
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
