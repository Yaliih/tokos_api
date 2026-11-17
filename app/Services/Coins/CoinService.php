<?php

namespace App\Services\Coins;

use App\Exceptions\CompetitionException;
use App\Models\CoinPurchase;
use App\Models\CoinTransaction;
use App\Models\CoinWithdrawal;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Le porte-monnaie : chaque mouvement passe par ici, dans une transaction,
 * avec le compte verrouillé pour éviter de dépenser deux fois les mêmes coins.
 */
class CoinService
{
    public function balance(User $user): int
    {
        return (int) User::whereKey($user->id)->value('coins');
    }

    /** Total gagné : votes reçus et cadeaux reçus. */
    public function earned(User $user): int
    {
        return (int) CoinTransaction::where('user_id', $user->id)->whereIn('type', CoinTransaction::EARNINGS)->sum('amount');
    }

    /** Ce qui peut sortir en argent : les gains pas encore retirés, dans la limite du solde. */
    public function withdrawable(User $user): int
    {
        $out = (int) CoinWithdrawal::where('user_id', $user->id)->whereIn('status', ['pending', 'paid'])->sum('coins');

        return max(0, min($this->balance($user), $this->earned($user) - $out));
    }

    /** Demande de retrait vers mobile money : les coins quittent le solde tout de suite. */
    public function requestWithdrawal(User $user, int $coins, string $operator, string $phone): CoinWithdrawal
    {
        $min = (int) config('coins.cashout.min');
        if ($coins < $min) {
            throw new CompetitionException('Le retrait minimum est de '.$min.' coins.', 422);
        }

        return DB::transaction(function () use ($user, $coins, $operator, $phone) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $can = $this->withdrawable($user);
            if ($coins > $can) {
                throw new CompetitionException('Tu peux retirer jusqu\'à '.$can.' coins : seuls les coins gagnés se retirent.', 422);
            }
            $w = CoinWithdrawal::create([
                'user_id' => $user->id, 'coins' => $coins, 'amount' => $coins * (int) config('coins.cashout.rate'),
                'currency' => config('coins.currency'), 'operator' => $operator, 'phone' => $phone, 'status' => 'pending',
            ]);
            $this->debit($user, $coins, 'withdrawal', 'Vers '.(CoinWithdrawal::OPERATORS[$operator] ?? $operator).' '.$phone, $w);

            return $w;
        });
    }

    /** Le versement est fait (à la main ou par l'agrégateur). */
    public function markWithdrawalPaid(CoinWithdrawal $w, ?string $ref = null): void
    {
        if ($w->status !== 'pending') {
            return;
        }
        $w->update(['status' => 'paid', 'paid_at' => now(), 'provider_ref' => $ref]);
    }

    /** Retrait refusé : les coins reviennent. */
    public function rejectWithdrawal(CoinWithdrawal $w, ?string $note = null): void
    {
        DB::transaction(function () use ($w, $note) {
            $w = CoinWithdrawal::whereKey($w->id)->lockForUpdate()->first();
            if ($w->status !== 'pending') {
                return;
            }
            $w->update(['status' => 'rejected', 'note' => $note]);
            $this->credit($w->user, $w->coins, 'refund', 'Retrait annulé'.($note ? ' : '.$note : ''), $w);
        });
    }

    /** Ajoute des coins. Retourne le nouveau solde. */
    public function credit(User $user, int $amount, string $type, ?string $description = null, ?Model $related = null, ?User $counterparty = null, array $meta = []): int
    {
        if ($amount <= 0) {
            return $this->balance($user);
        }

        return DB::transaction(function () use ($user, $amount, $type, $description, $related, $counterparty, $meta) {
            $u = User::whereKey($user->id)->lockForUpdate()->first();
            $u->coins = (int) $u->coins + $amount;
            $u->saveQuietly();
            $this->log($u, $amount, $type, $description, $related, $counterparty, $meta);

            return (int) $u->coins;
        });
    }

    /** Retire des coins, ou refuse s'il n'y en a pas assez. Retourne le nouveau solde. */
    public function debit(User $user, int $amount, string $type, ?string $description = null, ?Model $related = null, ?User $counterparty = null, array $meta = []): int
    {
        if ($amount <= 0) {
            return $this->balance($user);
        }

        return DB::transaction(function () use ($user, $amount, $type, $description, $related, $counterparty, $meta) {
            $u = User::whereKey($user->id)->lockForUpdate()->first();
            if ((int) $u->coins < $amount) {
                throw new CompetitionException('Pas assez de coins : il en faut '.$amount.', tu en as '.(int) $u->coins.'.', 402);
            }
            $u->coins = (int) $u->coins - $amount;
            $u->saveQuietly();
            $this->log($u, -$amount, $type, $description, $related, $counterparty, $meta);

            return (int) $u->coins;
        });
    }

    /**
     * Paiement d'une personne à une autre (cadeau, votes) : la plateforme garde sa part.
     * Retourne ce que le destinataire reçoit.
     */
    public function transfer(User $from, User $to, int $amount, string $outType, string $inType, string $description, ?Model $related = null, array $meta = []): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($from, $to, $amount, $outType, $inType, $description, $related, $meta) {
            $this->debit($from, $amount, $outType, $description, $related, $to, $meta);
            $net = $amount - $this->commission($amount);
            if ((int) $from->id !== (int) $to->id) {
                $this->credit($to, $net, $inType, $description, $related, $from, $meta + ['gross' => $amount]);
            }

            return $net;
        });
    }

    public function commission(int $amount): int
    {
        return (int) floor($amount * max(0, min(100, (int) config('coins.commission', 20))) / 100);
    }

    /** La personne qui reçoit les coins pour un participant : lui-même, ou le propriétaire de l'Espace. */
    public static function beneficiary(?Model $participant): ?User
    {
        if ($participant instanceof User) {
            return $participant;
        }
        if ($participant instanceof Space) {
            return User::find($participant->owner_id);
        }

        return null;
    }

    /**
     * Offrir des coins à une personne ou à un Espace (le propriétaire de l'Espace les reçoit).
     * Retourne ce que le destinataire reçoit après la part de la plateforme.
     */
    public function gift(User $from, Model $recipient, int $amount, ?string $message = null, ?Model $context = null): int
    {
        $to = self::beneficiary($recipient);
        if (! $to) {
            throw new CompetitionException('Impossible d\'offrir des coins à ce participant.');
        }
        if ((int) $to->id === (int) $from->id) {
            throw new CompetitionException('Tu ne peux pas t\'offrir des coins à toi-même.');
        }
        if ($amount < 1) {
            throw new CompetitionException('Choisis un nombre de coins.');
        }
        $name = $recipient instanceof Space ? $recipient->name : trim($to->first_name.' '.$to->last_name);
        $net = $this->transfer($from, $to, $amount, 'gift_sent', 'gift_received', 'Cadeau pour '.$name, $context ?? $recipient,
            array_filter(['message' => $message ? mb_substr($message, 0, 140) : null, 'space_id' => $recipient instanceof Space ? $recipient->id : null]));
        try {
            app(\App\Services\Social\NotificationService::class)->send($to, 'gift.received',
                trim($from->first_name.' '.$from->last_name).' t\'offre '.$amount.' coins',
                $message ? '« '.mb_substr($message, 0, 120).' »' : 'Tu reçois '.$net.' coins sur ton portefeuille.', '/portefeuille', $from);
        } catch (\Throwable $e) {
            report($e);
        }

        return $net;
    }

    /** Un achat confirmé : les coins arrivent (une seule fois). */
    public function completePurchase(CoinPurchase $purchase, ?string $providerRef = null): void
    {
        DB::transaction(function () use ($purchase, $providerRef) {
            $p = CoinPurchase::whereKey($purchase->id)->lockForUpdate()->first();
            if ($p->status === 'paid') {
                return;
            }
            $p->update(['status' => 'paid', 'paid_at' => now(), 'provider_ref' => $providerRef ?? $p->provider_ref]);
            $this->credit($p->user, $p->coins, 'purchase', $p->coins.' coins achetés', $p);
        });
    }

    private function log(User $u, int $amount, string $type, ?string $description, ?Model $related, ?User $counterparty, array $meta): void
    {
        CoinTransaction::create([
            'user_id' => $u->id,
            'amount' => $amount,
            'balance_after' => (int) $u->coins,
            'type' => $type,
            'description' => $description,
            'related_type' => $related ? $related::class : null,
            'related_id' => $related?->getKey(),
            'counterparty_id' => $counterparty?->id,
            'meta' => $meta ?: null,
        ]);
    }
}
