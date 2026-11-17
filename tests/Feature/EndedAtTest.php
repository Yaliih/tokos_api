<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Support\Presenter;
use Tests\TestCase;

/** « Fini il y a… » : la vraie fin, jamais « à l'instant » pour une date de fin prévue plus tard. */
class EndedAtTest extends TestCase
{
    public function test_finished_long_ago_is_not_finished_just_now(): void
    {
        $c = new Competition(['status' => 'completed', 'type' => 'contest']);
        $c->ends_at = now()->addDays(5);          // fin prévue plus tard
        $c->completed_at = now()->subDays(3);     // clôturé en avance, il y a 3 jours
        $this->assertSame('il y a 3 j', Presenter::ago(Presenter::endedAt($c)));
        $this->assertNull(Presenter::ago(now()->addDays(5))); // une date à venir n'est jamais « à l'instant »

        $live = new Competition(['status' => 'live', 'type' => 'contest']);
        $live->ends_at = now()->addHour();
        $this->assertNull(Presenter::endedAt($live));
        $live->ends_at = now()->subHours(2);
        $this->assertSame('il y a 2 h', Presenter::ago(Presenter::endedAt($live)));
    }
}
