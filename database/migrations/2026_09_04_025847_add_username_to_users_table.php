<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('id');
        });

        DB::table('users')
            ->select(['id', 'email'])
            ->orderBy('id')
            ->each(function (object $user): void {
                $baseUsername = Str::of($user->email)
                    ->before('@')
                    ->lower()
                    ->replaceMatches('/[^a-z0-9._-]+/', '')
                    ->trim('.-_')
                    ->limit(40, '')
                    ->toString();

                $baseUsername = $baseUsername !== '' ? $baseUsername : 'user';
                $username = $baseUsername;

                if (DB::table('users')->where('username', $username)->exists()) {
                    $counter = 0;

                    do {
                        $suffix = '-'.$user->id.($counter > 0 ? '-'.$counter : '');
                        $username = Str::limit($baseUsername, 50 - strlen($suffix), '').$suffix;
                        $counter++;
                    } while (DB::table('users')->where('username', $username)->exists());
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['username' => $username]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
