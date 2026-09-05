<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_guardian', function (Blueprint $table) {
            $table->string('relationship', 80)->nullable()->after('guardian_id');
        });

        DB::statement('UPDATE student_guardian SET relationship = (SELECT relationship FROM guardians WHERE guardians.id = student_guardian.guardian_id)');

        Schema::table('guardians', function (Blueprint $table) {
            $table->string('relationship', 80)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('guardians')->whereNull('relationship')->update(['relationship' => 'Guardian']);

        Schema::table('guardians', function (Blueprint $table) {
            $table->string('relationship', 80)->nullable(false)->change();
        });

        Schema::table('student_guardian', function (Blueprint $table) {
            $table->dropColumn('relationship');
        });
    }
};
