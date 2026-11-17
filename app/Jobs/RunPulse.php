<?php

namespace App\Jobs;

use App\Services\Social\Pulse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Publications automatiques du fil et alertes de classement (toutes les 10 minutes, dans la file d'attente). */
class RunPulse implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function handle(Pulse $pulse): void
    {
        $pulse->run();
    }
}
