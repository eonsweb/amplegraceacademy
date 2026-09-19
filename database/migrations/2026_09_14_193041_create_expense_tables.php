<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('expense_date');
            $table->decimal('amount', 12, 2);
            $table->string('payee')->nullable();
            $table->string('description', 500);
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 150)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->string('status', 20)->default('draft');
            $table->uuid('submission_key')->unique();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('recorded_by_name');
            $table->string('category_name', 100);
            $table->string('academic_year_name')->nullable();
            $table->string('term_name')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['expense_date', 'id']);
            $table->index(['status', 'expense_date']);
            $table->index(['expense_category_id', 'status', 'expense_date'], 'expense_category_reporting_index');
            $table->index(['academic_year_id', 'term_id', 'status', 'expense_date'], 'expense_period_reporting_index');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expense_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expense_lifecycle CHECK (
                (status = 'draft' AND recorded_at IS NULL AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
                OR (status = 'recorded' AND recorded_at IS NOT NULL AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
                OR (status = 'voided' AND recorded_at IS NOT NULL AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL))");
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expense_term_requires_year CHECK (term_id IS NULL OR academic_year_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
