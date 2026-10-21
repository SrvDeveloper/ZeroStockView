<?php

use App\Services\AmazonInventoryImportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('survey-files:prune')->daily();
Schedule::command('amazon-inventory:prune-snapshots')->dailyAt('01:00')->timezone('Asia/Tokyo')->onOneServer()->withoutOverlapping();
Schedule::command('amazon-inventory:queue')->dailyAt('09:00')->timezone('Asia/Tokyo')->onOneServer()->withoutOverlapping();
Schedule::call(fn () => app(AmazonInventoryImportService::class)->expireStale())
    ->name('amazon-inventory-expire-stale')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('survey:amazon-reconcile')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('queue:work amazon_inventory --queue=amazon-inventory --stop-when-empty --max-jobs=1 --tries=1 --timeout=4200')
    ->everyFiveMinutes()
    ->when(fn (): bool => DB::connection(config('queue.connections.amazon_inventory.connection'))
        ->table(config('queue.connections.amazon_inventory.table'))
        ->where('queue', 'amazon-inventory')->exists())
    ->onOneServer()
    ->withoutOverlapping(90);
