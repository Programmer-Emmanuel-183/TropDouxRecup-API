<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


//Notification et update abonnement quand expiration terminé.
//Test
Schedule::command('abonnements:handle-expiration')
    ->everyMinute();
//Production
// Schedule::command('abonnements:handle-expiration')
//     ->dailyAt('10:05');


//Desactiver les plats à une certaine heure et vider panier
Schedule::command('times:handle-time')
    ->everyMinute();