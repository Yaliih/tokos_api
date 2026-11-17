<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\User;

/**
 * Image d'aperçu (1200 × 630) affichée quand on partage un lien sur WhatsApp, Facebook, X…
 * Un duel : les deux camps face à face, leur pourcentage, le titre et le temps restant.
 * Une compétition : son média (performance du créateur ou couverture), le titre et le type.
 *
 * L'image est gardée en cache dans storage/app/public/og et refaite quand le score
 * ou le temps restant changent sensiblement.
 */
class ShareImage
{
    private const W = 1200;
    private const H = 630;
    private const BAND = 150; // bandeau du bas (titre)

    public static function forMatch(CompetitionMatch $m): string
    {
        $m->loadMissing(['competition', 'entryA.participant', 'entryB.participant']);
        $c = $m->competition;
        $scores = $m->status === 'completed' ? ['a' => (float) $m->score_a, 'b' => (float) $m->score_b] : $m->liveScores();
        $left = self::timeLeft($m->status === 'voting' ? $m->ends_at : null, $m->status === 'completed');
        $key = 'm'.$m->id.'-'.md5(round($scores['a']).'|'.$left.'|'.$m->updated_at);

        return self::cached($key, function () use ($m, $c, $scores, $left) {
            $img = self::canvas();
            $half = intdiv(self::W, 2);
            $h = self::H - self::BAND;
            foreach (['a' => 0, 'b' => $half] as $side => $x) {
                $e = $side === 'a' ? $m->entryA : $m->entryB;
                self::drawSide($img, $e, $x, 0, $half, $h, $side === 'a' ? 0 : 1);
                // Pourcentage dans le coin extérieur
                $pct = round($scores[$side]).'%';
                self::pill($img, $pct, $side === 'a' ? 28 : self::W - 28, 28, $side === 'a' ? 'left' : 'right', 34);
                // Nom en bas du camp
                if ($e) {
                    self::pill($img, self::cut($e->displayName(), 22), $side === 'a' ? 28 : self::W - 28, $h - 78, $side === 'a' ? 'left' : 'right', 24, true);
                }
            }
            // Séparation et « VS »
            imagefilledrectangle($img, $half - 3, 0, $half + 2, $h, self::color($img, '#FFFFFF'));
            imagefilledellipse($img, $half, intdiv($h, 2), 110, 110, self::color($img, '#FFFFFF'));
            imagefilledellipse($img, $half, intdiv($h, 2), 96, 96, self::color($img, '#15151C'));
            self::text($img, 'VS', 30, $half, intdiv($h, 2) + 11, '#FFFFFF', 'center');
            self::band($img, $c->title, ($c->typeLabel() ?: 'Duel').($left ? ' · '.$left : '').' · Vote sur Sosa');

            return $img;
        });
    }

    public static function forCompetition(Competition $c): string
    {
        $c->loadMissing('organizer');
        $left = self::timeLeft(in_array($c->status, ['open', 'live'], true) ? ($c->ends_at ?? $c->registration_ends_at) : null, $c->status === 'completed');
        $key = 'c'.$c->id.'-'.md5($left.'|'.$c->updated_at.'|'.$c->entries_count);

        return self::cached($key, function () use ($c, $left) {
            $img = self::canvas();
            $h = self::H - self::BAND;
            $owner = $c->type === Competition::TYPE_CHALLENGE
                ? $c->entries()->with('participant')->where('participant_type', $c->organizer_type)->where('participant_id', $c->organizer_id)->first()
                : null;
            $cover = $c->cover && ! preg_match('/\.(mp4|mov|webm|3gp)$/i', (string) $c->cover) ? self::load($c->cover) : null;
            if ($cover) {
                self::cover($img, $cover, 0, 0, self::W, $h);
            } else {
                self::drawSide($img, $owner, 0, 0, self::W, $h, 0, $c->organizer);
            }
            $verb = ['challenge' => 'Relève le défi', 'duel' => 'Viens voter', 'contest' => 'Participe ou vote', 'tournament' => 'Inscris-toi'][$c->type] ?? 'Viens voir';
            self::pill($img, $c->typeLabel(), 28, 28, 'left', 28);
            self::band($img, $c->title, $verb.($left ? ' · '.$left : '').' · Sosa');

            return $img;
        });
    }

    // ── Dessin ──────────────────────────────────────────────────

    private static function canvas()
    {
        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);
        imagefilledrectangle($img, 0, 0, self::W, self::H, self::color($img, '#6C5CE7'));

        return $img;
    }

    /** Un camp : sa photo (ou l'image de la vidéo) en grand, sinon le visage en cercle sur fond clair. */
    private static function drawSide($img, $entry, int $x, int $y, int $w, int $h, int $index, $fallbackParticipant = null): void
    {
        $media = collect($entry?->media ?? []);
        $visual = $media->first(fn ($md) => ($md['type'] ?? '') === 'image');
        $src = $visual ? self::load($visual['url'] ?? null) : null;
        if (! $src && ($video = $media->first(fn ($md) => ($md['type'] ?? '') === 'video')) && ! empty($video['thumbnail'])) {
            $src = self::load($video['thumbnail']);
        }
        if ($src) {
            self::cover($img, $src, $x, $y, $w, $h);

            return;
        }
        // Audio, texte ou rien : visage en cercle
        imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, self::color($img, $index ? '#E4E0FC' : '#EFEDFD'));
        $p = $entry?->participant ?? $fallbackParticipant;
        $face = $p ? self::load($p->avatar_url ?? null) : null;
        $d = min(300, $h - 120);
        $cx = $x + intdiv($w, 2);
        $cy = $y + intdiv($h, 2) - 10;
        imagefilledellipse($img, $cx, $cy, $d + 16, $d + 16, self::color($img, '#6C5CE7'));
        if ($face) {
            self::circle($img, $face, $cx, $cy, $d);
        } else {
            imagefilledellipse($img, $cx, $cy, $d, $d, self::color($img, '#8B7FF5'));
            $name = $entry?->displayName() ?? ($p->name ?? '?');
            self::text($img, mb_strtoupper(mb_substr((string) $name, 0, 1)), 110, $cx, $cy + 40, '#FFFFFF', 'center');
        }
        // Texte d'une performance écrite
        if (! $media->count() && $entry?->content) {
            self::text($img, self::cut($entry->content, 40), 24, $cx, $y + $h - 40, '#15151C', 'center', false);
        }
    }

    private static function band($img, string $title, string $sub): void
    {
        $y = self::H - self::BAND;
        imagefilledrectangle($img, 0, $y, self::W, self::H, self::color($img, '#FFFFFF'));
        self::text($img, self::fit($title, 40, self::W - 80, true), 40, 40, $y + 66, '#15151C');
        self::text($img, self::fit($sub, 24, self::W - 80, false), 24, 40, $y + 116, '#6C5CE7', 'left', false);
    }

    private static function pill($img, string $text, int $x, int $y, string $align, int $size, bool $dark = false): void
    {
        $font = self::font(true);
        $box = imagettfbbox($size, 0, $font, $text);
        $tw = abs($box[2] - $box[0]);
        $pad = (int) round($size * 0.7);
        $hgt = (int) round($size * 1.9);
        $x0 = $align === 'right' ? $x - $tw - 2 * $pad : $x;
        $bg = self::color($img, $dark ? '#15151C' : '#2A2A34'); // opaque : pas de recouvrements visibles
        self::rounded($img, $x0, $y, $x0 + $tw + 2 * $pad, $y + $hgt, intdiv($hgt, 2), $bg);
        imagettftext($img, $size, 0, $x0 + $pad, $y + intdiv($hgt, 2) + intdiv($size, 2) - 1, self::color($img, '#FFFFFF'), $font, $text);
    }

    private static function text($img, string $text, int $size, int $x, int $y, string $hex, string $align = 'left', bool $bold = true): void
    {
        $font = self::font($bold);
        if ($align === 'center') {
            $box = imagettfbbox($size, 0, $font, $text);
            $x -= intdiv(abs($box[2] - $box[0]), 2);
        }
        imagettftext($img, $size, 0, $x, $y, self::color($img, $hex), $font, $text);
    }

    private static function cover($img, $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) ($w / $scale);
        $ch = (int) ($h / $scale);
        imagecopyresampled($img, $src, $x, $y, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), $w, $h, $cw, $ch);
    }

    private static function circle($img, $src, int $cx, int $cy, int $d): void
    {
        $tmp = imagecreatetruecolor($d, $d);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        self::cover($tmp, $src, 0, 0, $d, $d);
        $r = $d / 2;
        $clear = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        for ($py = 0; $py < $d; $py++) {
            for ($px = 0; $px < $d; $px++) {
                if (($px - $r + .5) ** 2 + ($py - $r + .5) ** 2 > $r * $r) {
                    imagesetpixel($tmp, $px, $py, $clear);
                }
            }
        }
        imagealphablending($img, true);
        imagecopy($img, $tmp, $cx - (int) $r, $cy - (int) $r, 0, 0, $d, $d);
    }

    private static function rounded($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    /** @param int $alphaPct transparence en % (0 = opaque) */
    private static function color($img, string $hex, int $alphaPct = 0): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocatealpha($img, $r, $g, $b, (int) round(127 * $alphaPct / 100));
    }

    private static function font(bool $bold): string
    {
        return resource_path('fonts/'.($bold ? 'Poppins-Bold.ttf' : 'Poppins-Medium.ttf'));
    }

    /** Charge une image : d'abord depuis le disque (fichiers du site), sinon par le réseau, vite. */
    private static function load(?string $url)
    {
        if (! $url) {
            return null;
        }
        try {
            $data = null;
            // Lien du site vers un fichier (« /api/get-media/dossier&fichier,jpg ») : on lit le fichier directement
            if (preg_match('~/get-media/([^?#]+)~', $url, $mm)) {
                $rel = preg_replace('/,(?=[^,]*$)/', '.', str_replace('&', '/', urldecode($mm[1])));
                $url = $rel;
            }
            if (($pos = strpos($url, '/storage/')) !== false) {
                $path = storage_path('app/public/'.urldecode(substr($url, $pos + 9)));
                $data = is_file($path) ? file_get_contents($path) : null;
            } elseif (! str_starts_with($url, 'http')) {
                $path = storage_path('app/public/'.ltrim($url, '/'));
                $data = is_file($path) ? file_get_contents($path) : null;
            }
            if ($data === null && str_starts_with($url, 'http')) {
                $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 2], 'https' => ['timeout' => 2]]));
            }
            $img = $data ? @imagecreatefromstring($data) : null;

            return $img ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function timeLeft($endsAt, bool $over): ?string
    {
        if ($over) {
            return 'Terminé';
        }
        if (! $endsAt || now()->greaterThan($endsAt)) {
            return null;
        }
        $s = (int) now()->diffInSeconds($endsAt);

        return $s >= 86400 ? 'encore '.intdiv($s, 86400).' j' : ($s >= 3600 ? 'encore '.intdiv($s, 3600).' h' : 'dernières minutes');
    }

    /** Raccourcit un texte jusqu'à ce qu'il tienne dans la largeur donnée. */
    private static function fit(string $text, int $size, int $width, bool $bold): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $font = self::font($bold);
        $out = $text;
        while (mb_strlen($out) > 3) {
            $box = imagettfbbox($size, 0, $font, $out);
            if (abs($box[2] - $box[0]) <= $width) {
                return $out;
            }
            $text = mb_substr($text, 0, -1);
            $out = rtrim($text).'…';
        }

        return $out;
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    private static function cached(string $key, callable $draw): string
    {
        $dir = storage_path('app/public/og');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.$key.'.png';
        if (! is_file($path)) {
            // On retire les anciennes versions de la même image
            $prefix = explode('-', $key)[0].'-';
            foreach (glob($dir.'/'.$prefix.'*.png') ?: [] as $old) {
                @unlink($old);
            }
            $img = $draw();
            imagepng($img, $path, 6);
            imagedestroy($img);
        }

        return $path;
    }
}
