<?php

namespace App\Support;

/**
 * Durée (en secondes) d'un fichier audio ou vidéo.
 * getID3 (déjà dans composer.json) lit mp3, mp4, mov, m4a, wav, ogg, webm…
 * ffprobe sert de secours s'il est installé sur le serveur.
 */
class MediaDuration
{
    /**
     * WebM / Matroska (enregistrements faits dans le navigateur) : getID3 peut planter sur ces fichiers
     * (taille « inconnue » écrite par MediaRecorder), on ne lui passe donc pas.
     */
    public static function isMatroska(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (! $h) {
            return false;
        }
        $magic = fread($h, 4);
        fclose($h);

        return $magic === "\x1A\x45\xDF\xA3";
    }

    /** Un WebM contient-il une piste vidéo ? (codecs V_VP8, V_VP9, V_AV1, V_MPEG4…) */
    public static function matroskaHasVideo(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 65536);

        return (bool) preg_match('/V_(VP8|VP9|AV1|MPEG|MS|THEORA)/', $head);
    }

    public static function seconds(string $path): ?float
    {
        if (class_exists(\getID3::class) && ! self::isMatroska($path)) {
            try {
                $info = (new \getID3())->analyze($path);
                if (! empty($info['playtime_seconds'])) {
                    return (float) $info['playtime_seconds'];
                }
            } catch (\Throwable) {
                // on tente ffprobe
            }
        }

        if (function_exists('shell_exec') && ! in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $out = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($path).' 2>&1');
            if (is_string($out) && is_numeric(trim($out))) {
                return (float) trim($out);
            }
        }

        return null;
    }

    /** Largeur et hauteur d'une vidéo (getID3, puis ffprobe), ou null. */
    public static function dimensions(string $path): ?array
    {
        if (class_exists(\getID3::class) && ! self::isMatroska($path)) {
            try {
                $info = (new \getID3())->analyze($path);
                $w = (int) ($info['video']['resolution_x'] ?? 0);
                $h = (int) ($info['video']['resolution_y'] ?? 0);
                $rot = (int) ($info['video']['rotate'] ?? ($info['quicktime']['video']['rotate'] ?? 0));
                if ($w && $h) {
                    return in_array(abs($rot), [90, 270], true) ? ['width' => $h, 'height' => $w] : ['width' => $w, 'height' => $h];
                }
            } catch (\Throwable) {
                // ffprobe ensuite
            }
        }
        if (function_exists('shell_exec') && ! in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $out = @shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 '.escapeshellarg($path).' 2>&1');
            if (is_string($out) && preg_match('/^(\d+)x(\d+)/', trim($out), $m)) {
                return ['width' => (int) $m[1], 'height' => (int) $m[2]];
            }
        }

        return null;
    }

    public static function label(int $seconds): string
    {
        return $seconds >= 60
            ? intdiv($seconds, 60).' min'.($seconds % 60 ? ' '.($seconds % 60).' s' : '')
            : $seconds.' s';
    }
}
