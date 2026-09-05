<?php

namespace Database\Seeders;

use App\Models\SchoolSetting;
use Illuminate\Database\Seeder;

class SchoolSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = SchoolSetting::query()->firstOrCreate([
            'id' => 1,
        ], [
            'school_name' => config('app.name'),
            'school_initials' => 'AGA',
            'currency_code' => 'GHS',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '12-hour',
            'timezone' => 'Africa/Accra',
            'records_per_page' => 25,
        ]);

        if ($settings->school_initials === null) {
            $settings->update(['school_initials' => 'AGA']);
        }
    }
}
