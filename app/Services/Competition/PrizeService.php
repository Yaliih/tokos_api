<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\User;
use App\Services\Coins\CoinService;
use App\Services\Social\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Récompenses par place, en texte (« 100 $ + trophée ») et/ou en coins.
 *
 * - Places : une seule (1er) ou une plage (3e à 20e). Jamais deux fois la même place.
 * - Coins : le créateur doit déjà les avoir. Ils sont retirés de son portefeuille à la publication
 *   (bloqués), puis envoyés comme cadeaux aux gagnants à la fin, sans part pour Sosa.
 *   Places non attribuées (moins de participants que prévu) ou compétition annulée : rendus au créateur.
 * - Ex æquo (ex. les deux demi-finalistes d'un tournoi, 3e et 4e) : ils se partagent les places qu'ils occupent.
 */
class PrizeService
{
    public const MAX_TIERS = 20;

    public function __construct(private CoinService $coins, private NotificationService $notifications) {}

    /** Nettoie et vérifie les récompenses saisies. @return array<int, array{from: int, to: int, text: ?string, coins: int}> */
    public function normalize(?array $tiers, ?int $maxPlaces, bool $coinsAllowed): array
    {
        $out = collect($tiers ?? [])->map(function ($t) use ($coinsAllowed) {
            $from = (int) ($t['from'] ?? 0);
            $to = (int) (($t['to'] ?? null) ?: $from);

            return [
                'from' => $from, 'to' => max($from, $to),
                'text' => ($s = trim((string) ($t['text'] ?? ''))) !== '' ? mb_substr($s, 0, 120) : null,
                'coins' => $coinsAllowed ? max(0, min(10_000_000, (int) ($t['coins'] ?? 0))) : 0,
            ];
        })->filter(fn ($t) => $t['from'] >= 1 && ($t['text'] || $t['coins'] > 0))->sortBy('from')->values();

        if ($out->count() > self::MAX_TIERS) {
            throw new CompetitionException('Au plus '.self::MAX_TIERS.' lignes de récompenses.');
        }
        $last = 0;
        foreach ($out as $t) {
            if ($t['from'] <= $last) {
                throw new CompetitionException('La place '.$t['from'].' a déjà une récompense : les places ne doivent pas se chevaucher.');
            }
            if ($maxPlaces && $t['to'] > $maxPlaces) {
                throw new CompetitionException('Récompense pour la place '.$t['to'].', mais il n\'y aura que '.$maxPlaces.' participants au plus.');
            }
            $last = $t['to'];
        }

        return $out->all();
    }

    public static function total(array $tiers): int
    {
        return (int) collect($tiers)->sum(fn ($t) => ($t['to'] - $t['from'] + 1) * (int) $t['coins']);
    }

    /** « 1er », « 2e », « 3e à 20e » */
    public static function placeLabel(int $from, int $to): string
    {
        $p = fn ($n) => $n === 1 ? '1er' : $n.'e';

        return $from === $to ? $p($from) : $p($from).' à '.$p($to);
    }

    /** Résumé court pour l'étiquette « Récompense ». */
    public static function summary(array $tiers): ?string
    {
        $parts = collect($tiers)->map(fn ($t) => self::placeLabel($t['from'], $t['to']).' : '.implode(' + ', array_filter([
            $t['text'], $t['coins'] ? number_format($t['coins'], 0, ',', ' ').' coins' : null,
        ])));

        return $parts->isEmpty() ? null : mb_substr($parts->implode(' · '), 0, 255);
    }

    /** Bloque les coins du créateur (appelé à la publication, dans la même transaction). */
    public function escrow(User $creator, Competition $c, array $tiers): void
    {
        $total = self::total($tiers);
        if ($total <= 0) {
            return;
        }
        $have = $this->coins->balance($creator);
        if ($have < $total) {
            throw new CompetitionException('Pour offrir '.number_format($total, 0, ',', ' ').' coins de récompense, il faut les avoir : tu en as '.number_format($have, 0, ',', ' ').'.', 402);
        }
        $this->coins->debit($creator, $total, 'prize_escrow', 'Récompense bloquée pour « '.$c->title.' »', $c);
        $c->forceFill(['prize_escrow' => $total])->save();
    }

    /** Fin de la compétition : chaque gagnant reçoit ses coins, le reste revient au créateur. */
    public function payout(Competition $c): int
    {
        return DB::transaction(function () use ($c) {
            $c = Competition::query()->lockForUpdate()->find($c->id);
            if ($c->prize_settled_at || (int) $c->prize_escrow <= 0) {
                return 0;
            }
            $tiers = $c->prize_tiers ?? [];
            $coinsAt = function (int $place) use ($tiers): int {
                foreach ($tiers as $t) {
                    if ($place >= $t['from'] && $place <= $t['to']) {
                        return (int) $t['coins'];
                    }
                }

                return 0;
            };
            $paid = 0;
            $groups = $c->entries()->with('participant')->whereNotNull('rank')->whereIn('status', ['winner', 'active', 'eliminated'])
                ->orderBy('rank')->orderBy('id')->get()->groupBy('rank');
            foreach ($groups as $rank => $group) {
                // Ex æquo : ils occupent les places rank … rank + n - 1 et se les partagent
                $pool = 0;
                for ($p = (int) $rank; $p < (int) $rank + $group->count(); $p++) {
                    $pool += $coinsAt($p);
                }
                $each = intdiv($pool, max(1, $group->count()));
                if ($each <= 0) {
                    continue;
                }
                foreach ($group as $entry) {
                    $to = CoinService::beneficiary($entry->participant);
                    if (! $to) {
                        continue;
                    }
                    $this->coins->credit($to, $each, 'prize_won', 'Récompense : '.self::placeLabel((int) $rank, (int) $rank).' de « '.$c->title.' »', $c, $c->creator);
                    $paid += $each;
                    try {
                        $this->notifications->send($to, 'gift.received', 'Tu gagnes '.number_format($each, 0, ',', ' ').' coins',
                            'Ta récompense pour « '.$c->title.' » est sur ton portefeuille.', '/portefeuille', $c->organizer);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
            $rest = (int) $c->prize_escrow - $paid;
            if ($rest > 0 && $c->creator) {
                $this->coins->credit($c->creator, $rest, 'refund', 'Récompense non attribuée de « '.$c->title.' »', $c);
            }
            $c->forceFill(['prize_settled_at' => now()])->save();

            return $paid;
        });
    }

    /** Compétition annulée : tous les coins bloqués reviennent au créateur. */
    public function refund(Competition $c): void
    {
        DB::transaction(function () use ($c) {
            $c = Competition::query()->lockForUpdate()->find($c->id);
            if ($c->prize_settled_at || (int) $c->prize_escrow <= 0 || ! $c->creator) {
                return;
            }
            $this->coins->credit($c->creator, (int) $c->prize_escrow, 'refund', 'Récompense rendue : « '.$c->title.' » annulée', $c);
            $c->forceFill(['prize_settled_at' => now()])->save();
        });
    }
}
