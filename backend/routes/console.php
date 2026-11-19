<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (! app()->environment('local')) {
    Schedule::command('cards:import')->monthlyOn(1, '02:00')->withoutOverlapping(1440);
    Schedule::command('cards:refresh-edhrec')->dailyAt('07:00')->withoutOverlapping(1440);
}
Schedule::call(fn () => DB::table('practice_games')->where('created_at', '<', now()->subDays(2))->delete())->daily();
