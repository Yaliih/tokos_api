<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Services\Announcer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Une annonce envoyée depuis l'admin à beaucoup de monde. */
class SendAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1; // pas de double envoi si ça coupe au milieu

    public function __construct(public int $announcementId)
    {
    }

    public function handle(Announcer $announcer): void
    {
        if ($a = Announcement::find($this->announcementId)) {
            $announcer->send($a);
        }
    }
}
