<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionVote;
use App\Models\User;
use App\Support\Device;

/** Règles contre les faux votes : un même téléphone, un même réseau, des comptes tout neufs. */
class AntiFraud
{
    /**
     * Vérifie un vote avant de l'enregistrer. Refuse les abus nets, marque le reste.
     *
     * @return array{device_id: ?string, flagged: bool, flag_reason: ?string}
     */
    public function checkVote(User $user, Competition $c, ?int $matchId, ?string $ip): array
    {
        $device = Device::id();
        $reasons = [];

        if ($device) {
            $others = CompetitionVote::where('competition_id', $c->id)->where('device_id', $device)
                ->where('user_id', '!=', $user->id)->distinct()->count('user_id');
            if ($others >= (int) config('antifraud.accounts_per_device_per_competition', 2)) {
                throw new CompetitionException('Ce téléphone a déjà servi à voter avec d\'autres comptes dans cette compétition.', 429);
            }
            if ($others > 0) {
                $reasons[] = 'même téléphone';
            }
        }
        if ($ip && $matchId) {
            $fromIp = CompetitionVote::where('match_id', $matchId)->where('ip_address', $ip)
                ->where('created_at', '>=', now()->subHour())->count();
            if ($fromIp >= (int) config('antifraud.ip_block_per_hour', 120)) {
                throw new CompetitionException('Trop de votes depuis ce réseau. Réessaie plus tard.', 429);
            }
            if ($fromIp >= (int) config('antifraud.ip_flag_per_hour', 15)) {
                $reasons[] = 'même réseau';
            }
        }
        if ($user->created_at && $user->created_at->gt(now()->subMinutes((int) config('antifraud.fresh_account_minutes', 30)))) {
            $reasons[] = 'compte tout neuf';
        }
        if ($user->signup_device && User::where('signup_device', $user->signup_device)->where('id', '!=', $user->id)->exists()) {
            $reasons[] = 'comptes créés sur le même téléphone';
        }

        return ['device_id' => $device, 'flagged' => (bool) $reasons, 'flag_reason' => $reasons ? mb_substr(implode(', ', $reasons), 0, 60) : null];
    }

    /** Avant une inscription : pas plus de N comptes par téléphone en 30 jours. */
    public function checkSignup(): void
    {
        $device = Device::id();
        if ($device && User::where('signup_device', $device)->where('created_at', '>=', now()->subDays(30))->count() >= (int) config('antifraud.signups_per_device', 3)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone_number' => 'Trop de comptes créés depuis ce téléphone. Connecte-toi à ton compte existant.']);
        }
    }

    public function markSignup(User $user): void
    {
        $user->forceFill(['signup_device' => Device::id(), 'signup_ip' => request()->ip()])->saveQuietly();
    }
}
