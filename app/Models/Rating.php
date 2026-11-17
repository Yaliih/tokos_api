<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    protected $guarded = ['id'];

    public function rateable()
    {
        return $this->morphTo();
    }

    public static function currentSeason(): string
    {
        $now = now();

        return $now->year.'-T'.$now->quarter;
    }

    /** Classement de la semaine (lundi → dimanche) : tout le monde repart de zéro chaque lundi. */
    public static function currentWeek(): string
    {
        return now()->isoFormat('GGGG-[S]WW');
    }

    public static function seasonLabel(string $season): string
    {
        if ($season === 'all') {
            return 'Tous les temps';
        }
        if (str_contains($season, '-S')) {
            return $season === self::currentWeek() ? 'Cette semaine' : 'Semaine '.(int) substr($season, -2);
        }
        [$year, $q] = explode('-T', $season) + [null, null];
        // Une saison = un trimestre : janv.–mars, avr.–juin, juil.–sept., oct.–déc.
        $months = [1 => 'janv.–mars', 2 => 'avr.–juin', 3 => 'juil.–sept.', 4 => 'oct.–déc.'];

        return 'Saison '.($months[(int) $q] ?? 'T'.$q).' '.$year;
    }

    /** Saisons qui ont des points (la plus récente d'abord), toujours avec la saison en cours. */
    public static function seasons(): array
    {
        $current = self::currentSeason();
        $list = static::query()->where('season', 'like', '____-T_')->where('points', '>', 0)
            ->distinct()->pluck('season')->push($current)->unique()
            ->filter(fn ($s) => $s <= $current)->sortDesc()->values();

        return $list->map(fn ($s) => ['value' => $s, 'label' => self::seasonLabel($s), 'current' => $s === $current])->all();
    }
}
