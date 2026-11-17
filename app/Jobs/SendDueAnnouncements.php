<?php

namespace App\Jobs;

use App\Services\Announcer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Annonces programmées ou mensuelles dont l'heure est venue. */
class SendDueAnnouncements implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function handle(Announcer $announcer): void
    {
        $announcer->sendDue();
    }
}
