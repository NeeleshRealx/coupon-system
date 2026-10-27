<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Housekeeping only — correctness does NOT depend on this running on time
 * (see README "Disaster recovery"):
 *  - withoutOverlapping(): a slow run is never doubled up by the next minute's run.
 *  - onOneServer(): with several app servers, only one runs it (needs a shared
 *    cache store that supports atomic locks: database/redis/memcached).
 */
Schedule::command('coupons:expire')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
