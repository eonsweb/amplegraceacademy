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
        SchoolSetting::query()->firstOrCreate([
            'id' => 1,
        ], [
            'school_name' => config('app.name'),
        ]);
    }
}
