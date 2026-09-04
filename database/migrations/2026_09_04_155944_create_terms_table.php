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
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('term_order');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->nullable()->unique();
            $table->timestamps();

            $table->unique(['academic_year_id', 'name']);
            $table->unique(['academic_year_id', 'term_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
