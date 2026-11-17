<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CronRunner;

/** Appelé chaque minute par cron-job.org : /api/cron/{code secret}. */
class CronController extends Controller
{
    public function run(string $token)
    {
        abort_unless(CronRunner::check($token), 404);
        ignore_user_abort(true); // si cron-job.org raccroche, le travail continue

        $r = CronRunner::run();

        return response($r['ran'] ? 'ok '.$r['ms'].'ms, '.$r['jobs'].' tâche(s)' : 'déjà en cours', 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
