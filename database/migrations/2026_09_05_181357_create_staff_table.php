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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('staff_number', 30)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('role_title', 120);
            $table->string('department', 120)->nullable();
            $table->string('employment_type', 30)->nullable();
            $table->date('employment_date')->nullable();
            $table->string('photo')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['status', 'last_name', 'first_name']);
            $table->index(['department', 'status']);
        });

        Schema::create('staff_number_sequences', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_number_sequences');
        Schema::dropIfExists('staff');
    }
};
