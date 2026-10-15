<?php

use App\Http\Controllers\Api\DigiflazzProductController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static function (): void {
    app(DigiflazzProductController::class)->sync();
})->name('sync-digiflazz-mobile-legends')->everyTenMinutes()->withoutOverlapping();
