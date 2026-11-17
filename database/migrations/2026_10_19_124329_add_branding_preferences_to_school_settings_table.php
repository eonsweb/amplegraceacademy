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
        Schema::table('school_settings', function (Blueprint $table) {
            $table->string('dark_logo')->nullable();
            $table->string('dashboard_motto', 255)->nullable();
            $table->string('login_heading', 100)->nullable();
            $table->string('login_message', 300)->nullable();
            $table->string('copyright_company', 150)->nullable();
            $table->string('primary_color', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn(['dark_logo', 'dashboard_motto', 'login_heading', 'login_message', 'copyright_company', 'primary_color']);
        });
    }
};
