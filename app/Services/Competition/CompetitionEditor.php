<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Modifier une publication, sans tricher :
 * - seul l'organisateur, et jamais une fois terminée, annulée, ou quand le temps est écoulé (tous les types) ;
 * - tant que personne n'a répondu, participé ni voté : titre, texte, discipline (et règles, prix pour un concours ou un tournoi) ;
 * - ensuite, défi et duel ne bougent plus (les réponses et les votes portent sur ce qui a été publié) ;
 *   concours et tournoi gardent la description et le prix modifiables (précisions, nouveau sponsor) ;
 * - la vidéo, la photo ou l'audio publiés ne changent jamais : on annule et on republie.
 * La publication affiche ensuite « modifié ».
 */
class CompetitionEditor
{
    /** @return array{can: bool, fields: string[], note: ?string, text: ?string} */
    public function rules(Competition $c, ?User $user): array
    {
        $none = fn (?string $note) => ['can' => false, 'fields' => [], 'note' => $note, 'text' => null];
        if (! $user || ! $c->canManage($user) || \App\Support\Features::off('editing')) {
            return $none(null);
        }
        if (in_array($c->status, [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED], true)) {
            return $none($c->status === Competition::STATUS_CANCELLED ? 'Publication annulée : elle ne peut plus être modifiée.' : 'Terminé : la publication ne peut plus être modifiée.');
        }
        // Le temps est écoulé (même si la clôture automatique n'est pas encore passée) : plus rien ne change
        if ($c->timeIsUp()) {
            return $none('Le temps est écoulé : la publication ne peut plus être modifiée.');
        }
        $big = in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true);
        $versus = $c->format === 'versus';
        $owner = $big || $versus ? null : $this->ownerEntry($c);
        $text = $big ? $c->description : self::body($owner?->content, $c->title);

        if (! $this->hasActivity($c)) {
            $fields = $big ? ['title', 'text', 'category', 'rules', 'prize'] : ($versus ? ['title', 'category'] : ['title', 'text', 'category']);
            $fields = \App\Support\Features::on('prizes') ? $fields : array_values(array_diff($fields, ['prize']));

            return ['can' => true, 'fields' => $fields, 'text' => $text,
                'note' => $big ? null : 'Dès que quelqu\'un répond ou vote, la publication ne pourra plus changer.'];
        }
        if ($big) {
            $prize = \App\Support\Features::on('prizes');

            return ['can' => true, 'fields' => $prize ? ['text', 'prize'] : ['text'], 'text' => $text,
                'note' => $prize ? 'Des participants sont déjà là : seuls la description et la récompense peuvent encore changer.' : 'Des participants sont déjà là : seule la description peut encore changer.'];
        }

        return $none('Des gens ont déjà répondu ou voté : ce qui a été publié ne peut plus changer.');
    }

    public function update(User $user, Competition $c, array $input): Competition
    {
        $rules = $this->rules($c, $user);
        if (! $rules['can']) {
            throw new CompetitionException($rules['note'] ?: 'Seul l\'organisateur peut modifier.', 403);
        }
        $big = in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true);
        $f = array_flip($rules['fields']);
        $data = Validator::make($input, [
            'title' => ($big ? 'required' : 'nullable').'|string|min:3|max:120',
            'text' => 'nullable|string|max:'.($big ? 5000 : 1000),
            'category' => ['nullable', Rule::in(array_keys(config('competition.categories')))],
            'rules' => 'nullable|string|max:5000',
            'prize' => 'nullable|string|max:255',
        ], [], ['title' => 'titre', 'text' => 'texte', 'category' => 'discipline', 'rules' => 'règles', 'prize' => 'prix'])->validate();
        $data = array_intersect_key($data, $f);
        foreach ($data as $k => $v) {
            $data[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        }

        return DB::transaction(function () use ($c, $data, $big) {
            $changes = [];
            if (array_key_exists('title', $data)) {
                $title = $data['title'];
                if (! $title) {
                    // Défi, duel : sans titre, la première ligne du texte, comme à la publication
                    $line = trim((string) strtok(trim((string) ($data['text'] ?? $this->ownerEntry($c)?->content ?? '')), "\n"));
                    $title = mb_strlen($line) >= 3 ? \Illuminate\Support\Str::limit($line, 110) : $c->title;
                }
                $changes['title'] = $title;
            }
            foreach (['category', 'rules', 'prize'] as $k) {
                if (array_key_exists($k, $data)) {
                    $changes[$k] = $data[$k];
                }
            }
            if (array_key_exists('text', $data)) {
                if ($big) {
                    $changes['description'] = $data['text'];
                } elseif ($owner = $this->ownerEntry($c)) {
                    // Défi ou duel sans média : il faut garder un texte
                    // Défi en texte : la première ligne reste le titre, comme à la publication
                    $owner->update(['content' => $c->entryFormat() === 'text'
                        ? trim(($changes['title'] ?? $c->title).($data['text'] ? "\n".$data['text'] : ''))
                        : $data['text']]);
                }
            }
            $c->fill($changes);
            if (! $c->isDirty() && ! array_key_exists('text', $data)) {
                return $c;
            }
            $c->edited_at = now();
            $c->save();
            if ($c->post_id) {
                Post::whereKey($c->post_id)->update(['content' => $c->description ?: $c->title]);
            }

            return $c;
        });
    }

    /** Quelqu'un d'autre que l'organisateur a répondu, participé, ou des votes existent déjà. */
    public function hasActivity(Competition $c): bool
    {
        if ($c->votes_count > 0 || $c->status === Competition::STATUS_LIVE && in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            return true;
        }
        $owner = $this->ownerEntry($c);

        return $c->entries()->whereNotIn('status', ['withdrawn', 'rejected'])
            ->when($owner, fn ($q) => $q->whereKeyNot($owner->id))
            ->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $c->created_by))
            ->exists();
    }

    /** Le texte sans sa première ligne quand celle-ci est déjà le titre. */
    public static function body(?string $content, ?string $title): ?string
    {
        $content = trim((string) $content);
        $title = trim((string) $title);
        if ($title !== '' && str_starts_with($content, $title)) {
            $content = ltrim(mb_substr($content, mb_strlen($title)));
        }

        return $content === '' ? null : $content;
    }

    private function ownerEntry(Competition $c): ?CompetitionEntry
    {
        return $c->entries()->where('participant_type', $c->organizer_type)->where('participant_id', $c->organizer_id)->orderBy('id')->first();
    }
}
