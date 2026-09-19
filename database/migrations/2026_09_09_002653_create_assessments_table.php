<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('type', 30);
            $table->decimal('maximum_score', 8, 2);
            $table->date('assessment_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['academic_year_id', 'term_id', 'class_level_id', 'subject_id'], 'assessments_context_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
