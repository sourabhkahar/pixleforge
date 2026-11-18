<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for lost webhooks / dropped polling jobs. Needs `php artisan schedule:work`
// locally, or the scheduler enabled on the host (Laravel Cloud: toggle "Scheduler").
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
