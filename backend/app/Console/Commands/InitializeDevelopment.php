<?php

namespace App\Console\Commands;

use Database\Seeders\DevelopmentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeDevelopment extends Command
{
    protected $signature = 'cards:initialize-development';

    protected $description = 'Seed fake cards and EDHRec popularity into an empty development database';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Development initialization is only allowed in the local environment.');

            return self::FAILURE;
        }
        if (DB::table('cards')->exists()) {
            $this->info('Card data already exists; leaving it unchanged.');

            return self::SUCCESS;
        }

        return $this->call('db:seed', ['--class' => DevelopmentSeeder::class, '--force' => true]);
    }
}
