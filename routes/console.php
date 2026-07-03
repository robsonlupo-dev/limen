<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:reconcile')->everyTenMinutes();
// withoutOverlapping: a slow Asaas run must not stack two reconciles and pile on
// rate-limit pressure (a 429 mid-reconcile must never be read as a failed transfer).
Schedule::command('payouts:reconcile')->everyTenMinutes()->withoutOverlapping();
