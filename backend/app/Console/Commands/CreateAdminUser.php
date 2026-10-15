<?php

namespace App\Console\Commands;

use App\Mdm\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Creates (or resets) an admin-site account and prints a generated password once. */
class CreateAdminUser extends Command
{
    protected $signature = 'mdm:create-user {email} {name} {--role=super_admin : super_admin, manager or coordinator}';

    protected $description = 'Create or reset an admin-site account';

    public function handle(): int
    {
        $role = Role::tryFrom($this->option('role'));
        if (! $role) {
            $this->error('Role must be one of: '.implode(', ', array_column(Role::cases(), 'value')));

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);
        $user = User::updateOrCreate(
            ['email' => strtolower($this->argument('email'))],
            ['name' => $this->argument('name'), 'role' => $role, 'password' => $password],
        );

        $this->info("{$role->label()} account ready: {$user->email}");
        $this->line("Password (shown once, change it after signing in): {$password}");

        return self::SUCCESS;
    }
}
