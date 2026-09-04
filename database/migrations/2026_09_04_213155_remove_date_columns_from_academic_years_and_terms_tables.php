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
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on']);
        });

        Schema::table('terms', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('name');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        Schema::table('terms', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('term_order');
            $table->date('ends_on')->nullable()->after('starts_on');
        });
    }
};
