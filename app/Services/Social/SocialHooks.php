<?php

namespace App\Services\Social;

use App\Models\Competition;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Space;
use App\Models\User;
use App\Support\Participant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Branche les actions existantes (suivre, rejoindre un Espace, publier, commenter)
 * sur le fil d'activité et les notifications. Ne bloque jamais l'action d'origine.
 */
class SocialHooks
{
    public function __construct(private ActivityService $activities, private NotificationService $notifications)
    {
    }

    public function followed(User $follower, User $followed): void
    {
        $this->safe(function () use ($follower, $followed) {
            $this->activities->record($follower, 'user.followed', $followed, null, ['user_name' => Participant::name($followed)]);
            $this->notifications->send([$followed->id], 'follower.new', 'Nouvel abonné', Participant::name($follower).' te suit. Défie-le ou suis-le en retour !', '/@'.$follower->username, $follower);
        });
    }

    public function joinedSpace(User $user, Space $space): void
    {
        $this->safe(function () use ($user, $space) {
            $this->activities->record($user, 'space.joined', $space, $space, ['space_name' => $space->name]);
            $this->notifications->send(NotificationService::recipientsFor($space), 'space.joined', 'Nouveau membre', Participant::name($user).' a rejoint '.$space->name.'.', '/espaces/'.$space->slug, $user, [], $user->id);
        });
    }

    public function createdSpace(User $user, Space $space): void
    {
        $this->safe(fn () => $this->activities->record($user, 'space.created', $space, $space, ['space_name' => $space->name], 2));
    }

    public function postCreated(Post $post): void
    {
        if (in_array($post->type, ['competition'], true) || ! $post->author) {
            return;
        }
        $this->safe(fn () => $this->activities->record($post->author, 'post.created', $post, $post->author instanceof Space ? $post->author : null, [
            'excerpt' => Str::limit((string) $post->content, 140),
            'type' => $post->type,
        ]));
    }

    public function commented(PostComment $comment): void
    {
        $this->safe(function () use ($comment) {
            $post = $comment->post;
            if (! $post) {
                return;
            }
            $author = $comment->author ?? User::find($comment->user_id);
            $competition = $post->type === 'competition' ? Competition::where('post_id', $post->id)->first() : null;
            $url = $competition ? $competition->url().'?tab=commentaires' : '/p/'.$post->slug;
            $recipients = [$post->user_id];
            if ($comment->parent_id && ($parent = PostComment::find($comment->parent_id))) {
                $recipients[] = $parent->user_id;
            }
            $this->notifications->send($recipients, 'comment.new', 'Nouveau commentaire',
                Participant::name($author).' : « '.Str::limit($comment->content, 80).' »', $url, $author, [], $comment->user_id);
            // Les personnes et Espaces cités dans le commentaire (sauf ceux déjà prévenus ci-dessus)
            if (\App\Support\Mentions::handles($comment->content)) {
                $where = $competition ? 'en commentaire de « '.Str::limit($competition->title, 40).' »' : 'en commentaire';
                \App\Support\Mentions::notify($comment->content, $author, $where, $url, array_filter([(int) $comment->user_id, ...$recipients]));
            }
        });
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('SocialHooks: '.$e->getMessage());
        }
    }
}
