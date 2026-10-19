<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;

#[Signature('authorization:grant-academic-setup {username : Existing active authorization manager approved by the operator}')]
#[Description('Explicitly grant only Academic Setup access to an existing authorization manager')]
class GrantAcademicSetupAccess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::query()->where('username', $this->argument('username'))->first();

        if ($user === null || ! $user->is_active || ! collect(Permissions::critical())->every(
            fn (string $permission): bool => $user->checkPermissionTo($permission, 'web'),
        )) {
            $this->error('Choose an existing active user with complete authorization management permissions.');

            return self::FAILURE;
        }

        $permission = Permission::findOrCreate(Permissions::ACADEMIC_SETUP_VIEW, 'web');
        $user->givePermissionTo($permission);

        $this->info('Academic Setup access granted to '.$user->username.'. Existing role and permission assignments were preserved.');

        return self::SUCCESS;
    }
}
