<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Demo City Timezone
    |--------------------------------------------------------------------------
    |
    | All scheduling math (business hours, cutoff, ETAs) is evaluated in this
    | timezone. ClockService::now() returns "now" in this zone.
    |
    */

    'timezone' => env('LOGISTICS_TIMEZONE', 'America/Chicago'),

    /*
    |--------------------------------------------------------------------------
    | Business Hours
    |--------------------------------------------------------------------------
    |
    | Per-weekday open/close times for the demo city (24h "HH:MM"). A null day
    | is closed. ClockService throws if this key is missing entirely.
    |
    */

    'hours' => [
        'monday' => ['open' => '08:00', 'close' => '18:00'],
        'tuesday' => ['open' => '08:00', 'close' => '18:00'],
        'wednesday' => ['open' => '08:00', 'close' => '18:00'],
        'thursday' => ['open' => '08:00', 'close' => '18:00'],
        'friday' => ['open' => '08:00', 'close' => '18:00'],
        'saturday' => ['open' => '09:00', 'close' => '13:00'],
        'sunday' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Same-day Cutoff
    |--------------------------------------------------------------------------
    |
    | A same-day booking must arrive before this local time (and with enough
    | business hours left) to be accepted as same_day.
    |
    */

    'cutoff' => env('LOGISTICS_CUTOFF', '14:00'),

    /*
    |--------------------------------------------------------------------------
    | Next Window
    |--------------------------------------------------------------------------
    |
    | Promised turnaround, in business hours, for a "next" window job. Used by
    | later iterations when computing next-window ETAs.
    |
    */

    'next_window' => [
        'hours' => (int) env('LOGISTICS_NEXT_WINDOW_HOURS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leg Minutes
    |--------------------------------------------------------------------------
    |
    | Per-leg minute estimates for a single job. Their sum is the minimum
    | business time a same-day job needs to finish, and iter-4 uses them to
    | build ETAs. Sensible demo defaults; tune per city later.
    |
    */

    'leg_minutes' => [
        'to_pickup' => 30,
        'at_pickup' => 15,
        'to_drop' => 45,
        'at_drop' => 15,
    ],

];
