<?php

namespace App\Jobs;

use App\Services\Social\Supporters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Relances des votants et anciens participants (voir Supporters), hors de la requête de la personne qui agit. */
class NotifySupporters implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public const ACTIONS = ['matchClosed', 'competitionCompleted', 'leaderChanged', 'favoriteJoined', 'newCompetition'];

    public function __construct(public string $action, public array $args = [])
    {
    }

    public function handle(Supporters $supporters): void
    {
        if (in_array($this->action, self::ACTIONS, true)) {
            $supporters->{$this->action}(...$this->args);
        }
    }
}
