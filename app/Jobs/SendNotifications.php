<?php

namespace App\Jobs;

use App\Services\Social\NotificationService;
use App\Support\Participant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Une notification pour beaucoup de monde : la cloche de chacun, puis le push (voir NotificationService). */
class SendNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public array $ids,
        public string $type,
        public string $title,
        public string $body,
        public ?string $url,
        public ?string $actorType,
        public ?int $actorId,
        public array $extra = [],
        public ?int $exceptUserId = null,
    ) {
    }

    public function handle(NotificationService $notifications): void
    {
        $actor = $this->actorType ? Participant::fromTypeAndId($this->actorType, $this->actorId) : null;
        $notifications->sendNow($this->ids, $this->type, $this->title, $this->body, $this->url, $actor, $this->extra, $this->exceptUserId);
    }
}
