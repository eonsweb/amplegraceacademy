<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['TUITION' => 'Tuition', 'FEEDING' => 'Feeding', 'TRANSPORT' => 'Transportation', 'BOOKS' => 'Books', 'EXAM' => 'Examination', 'PTA' => 'PTA', 'UNIFORM' => 'Uniform', 'ICT' => 'ICT', 'OTHER' => 'Other'];
        $rows = [];
        foreach ($types as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('fee_types')->upsert($rows, ['code'], ['code']);
    }
}
