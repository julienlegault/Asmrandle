<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeDevelopment extends Command
{
    protected $signature = 'cards:initialize-development';

    protected $description = 'Import cards and seed random popularity into an empty development database';

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

        return $this->call('cards:import', ['--seed-random' => true]);
    }
}
