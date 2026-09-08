<?php

namespace App\Actions\Staff;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;

class CreateStaff
{
    private const SEQUENCE_KEY = 'staff';

    /** @param array<string, mixed> $staffData */
    public function handle(array $staffData): Staff
    {
        unset($staffData['staff_number']);

        return DB::transaction(function () use ($staffData): Staff {
            $now = now();

            DB::table('staff_number_sequences')->insertOrIgnore([
                'key' => self::SEQUENCE_KEY,
                'current_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = DB::table('staff_number_sequences')
                ->where('key', self::SEQUENCE_KEY)
                ->lockForUpdate()
                ->firstOrFail(['key', 'current_value']);
            $nextValue = (int) $sequence->current_value + 1;

            DB::table('staff_number_sequences')
                ->where('key', self::SEQUENCE_KEY)
                ->update(['current_value' => $nextValue, 'updated_at' => $now]);

            $staff = new Staff;
            $staff->staff_number = sprintf('STF%06d', $nextValue);
            $staff->fill($staffData)->save();

            return $staff;
        }, attempts: 5);
    }
}
