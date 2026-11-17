<?php

namespace App\Support;

/**
 * Ondes d'un audio pour l'affichage. On utilise les vraies valeurs envoyées par l'app
 * ou le navigateur (metadata.peaks) ; sinon des ondes stables générées à partir de l'URL,
 * pour que chaque audio ait toujours sa forme.
 */
class Wave
{
    public static function peaks(array $media, int $bars = 48): array
    {
        $peaks = $media['metadata']['peaks'] ?? null;
        if (is_array($peaks) && count($peaks) >= 4) {
            $peaks = array_map('floatval', $peaks);
            $max = max($peaks) ?: 1;
            $out = [];
            $step = count($peaks) / $bars;
            for ($i = 0; $i < $bars; $i++) {
                $slice = array_slice($peaks, (int) floor($i * $step), max(1, (int) ceil($step)));
                $out[] = round(max(0.12, min(1, (max($slice) ?: 0) / $max)), 2);
            }

            return $out;
        }

        // Ondes de remplacement, toujours les mêmes pour un même fichier
        mt_srand(crc32((string) ($media['url'] ?? 'audio')));
        $out = [];
        $v = 0.5;
        for ($i = 0; $i < $bars; $i++) {
            $v = max(0.15, min(1, $v + (mt_rand(-30, 30) / 100)));
            $out[] = round($v * (0.65 + 0.35 * sin($i / $bars * M_PI)), 2);
        }
        mt_srand();

        return $out;
    }

    public static function duration(array $media): ?string
    {
        $d = $media['metadata']['duration'] ?? null;

        return $d ? sprintf('%d:%02d', intdiv((int) $d, 60), (int) $d % 60) : null;
    }
}
