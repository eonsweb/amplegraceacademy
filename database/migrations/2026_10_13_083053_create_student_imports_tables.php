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
        Schema::create('student_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('preview');
            $table->date('enrollment_date');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('student_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('student_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('payload');
            $table->string('status', 20);
            $table->json('messages');
            $table->boolean('generate_number')->default(false);
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('guardians_created')->default(0);
            $table->unsignedTinyInteger('guardians_linked')->default(0);
            $table->timestamps();
            $table->unique(['student_import_id', 'row_number']);
            $table->index(['student_import_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_import_rows');
        Schema::dropIfExists('student_imports');
    }
};
