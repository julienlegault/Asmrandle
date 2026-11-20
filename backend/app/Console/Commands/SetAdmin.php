<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SetAdmin extends Command
{
    protected $signature = 'admin:set {username : Admin username}';

    protected $description = 'Create an admin account or change its password';

    public function handle(): int
    {
        $username = $this->argument('username');
        if (! preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/', $username)) {
            $this->error('Username must be 1-64 letters, numbers, dots, underscores or hyphens.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (12-72 bytes)');
        $confirmation = $this->secret('Confirm password');
        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 72 || $password !== $confirmation) {
            $this->error('Passwords must match and be 12-72 bytes long.');

            return self::FAILURE;
        }

        $hash = Hash::make($password);
        DB::table('admin_accounts')->updateOrInsert(
            ['username' => $username],
            fn (bool $exists) => $exists
                ? ['password' => $hash, 'updated_at' => now()]
                : ['password' => $hash, 'created_at' => now(), 'updated_at' => now()],
        );
        $this->info('Admin credentials saved.');

        return self::SUCCESS;
    }
}
