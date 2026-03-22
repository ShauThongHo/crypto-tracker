<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    ->comment(Inspiring::quote());
})->describe('Display an inspiring quote');
