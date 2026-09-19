<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('fee_structures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['academic_year_id', 'term_id', 'class_level_id', 'fee_type_id'], 'fee_structure_context_unique');
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_level_id')->constrained()->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->string('invoice_number', 40)->unique();
            $table->string('student_name');
            $table->string('admission_number');
            $table->string('class_name');
            $table->string('academic_year_name');
            $table->string('term_name');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unique(['enrollment_id', 'term_id']);
            $table->unique(['student_id', 'term_id']);
            $table->index(['academic_year_id', 'term_id', 'class_level_id', 'voided_at'], 'invoice_reporting_index');
            $table->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->string('receipt_number', 40)->unique();
            $table->uuid('submission_key')->unique();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30);
            $table->string('reference', 150)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('received_by_name');
            $table->index(['payment_date', 'id']);
            $table->index(['payment_method', 'payment_date']);
            $table->index(['student_id', 'payment_date']);
            $table->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unique(['payment_id', 'invoice_id']);
            $table->timestamps();
        });
        Schema::create('financial_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 50);
            $table->string('record_type', 30);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->json('changes')->nullable();
            $table->timestamp('created_at');
            $table->index(['record_type', 'record_id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE fee_structures ADD CONSTRAINT fee_structure_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_item_valid CHECK (quantity > 0 AND unit_amount >= 0 AND amount = quantity * unit_amount)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        foreach (['financial_audits', 'payment_allocations', 'payments', 'invoice_items', 'invoices', 'fee_structures', 'fee_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
