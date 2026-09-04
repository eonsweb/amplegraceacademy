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
            $table->string('dashboard_logo')->nullable()->after('address');
            $table->string('login_logo')->nullable()->after('dashboard_logo');
            $table->string('favicon')->nullable()->after('login_logo');
            $table->string('currency_code', 3)->default('GHS')->after('favicon');
            $table->string('date_format', 20)->default('DD/MM/YYYY')->after('currency_code');
            $table->string('time_format', 10)->default('12-hour')->after('date_format');
            $table->string('timezone', 64)->default('Africa/Accra')->after('time_format');
            $table->unsignedSmallInteger('records_per_page')->default(25)->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn([
                'dashboard_logo',
                'login_logo',
                'favicon',
                'currency_code',
                'date_format',
                'time_format',
                'timezone',
                'records_per_page',
            ]);
        });
    }
};
