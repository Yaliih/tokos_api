<?php

namespace App\Services;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Allège les médias avant de les ranger :
 * - vidéo : H.264 + AAC en MP4, 720p, lecture qui démarre avant la fin du téléchargement (faststart) ;
 * - audio : AAC en M4A, 96 kb/s ;
 * - photo : 1600 px au plus, JPEG qualité 82, orientation du téléphone respectée.
 * Le résultat n'est gardé que s'il est plus léger (ou si le format d'origine passe mal partout, comme le WebM sur iPhone).
 */
class MediaCompressor
{
    public function ffmpegAvailable(): bool
    {
        return Cache::remember('ffmpeg.ok.'.md5((string) config('media.ffmpeg')), 3600, function () {
            try {
                $p = new Process([config('media.ffmpeg'), '-version']);
                $p->setTimeout(10)->run();

                return $p->isSuccessful();
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * Compresse un fichier envoyé. Retourne [fichier à ranger, extension, infos] ;
     * le fichier d'origine si rien n'a pu être fait.
     *
     * @return array{0: UploadedFile|File, 1: string, 2: array}
     */
    public function compress(UploadedFile $file, string $type): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        if (! config('media.compress')) {
            return [$file, $ext, []];
        }
        try {
            $out = match ($type) {
                'video' => $this->video($file->getRealPath()),
                'audio' => $this->audio($file->getRealPath()),
                'image' => $this->image($file->getRealPath()),
                default => null,
            };
        } catch (\Throwable $exception) {
            Log::warning('Compression impossible : '.$exception->getMessage());
            $out = null;
        }
        if (! $out || ! is_file($out[0]) || filesize($out[0]) === 0) {
            return [$file, $ext, []];
        }
        [$path, $newExt, $info] = $out;
        $before = (int) $file->getSize();
        $after = (int) filesize($path);
        // Plus lourd qu'avant et format d'origine déjà lisible partout : on garde l'original
        $portable = in_array($ext, ['mp4', 'm4a', 'mp3', 'jpg', 'jpeg', 'png', 'webp', 'aac'], true);
        if ($after >= $before && $portable) {
            @unlink($path);

            return [$file, $ext, []];
        }

        return [new File($path), $newExt, $info + ['original_size' => $before, 'size' => $after]];
    }

    private function run(array $args): bool
    {
        if (! $this->ffmpegAvailable()) {
            return false;
        }
        @set_time_limit((int) config('media.timeout') + 30);
        $p = new Process(array_merge([config('media.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y'], $args));
        $p->setTimeout((int) config('media.timeout'))->run();
        if (! $p->isSuccessful()) {
            Log::warning('ffmpeg : '.mb_substr($p->getErrorOutput(), 0, 500));
        }

        return $p->isSuccessful();
    }

    private function tmp(string $ext): string
    {
        return tempnam(sys_get_temp_dir(), 'tokos_').'.'.$ext;
    }

    /** @return array{0: string, 1: string, 2: array}|null */
    private function video(string $in): ?array
    {
        $c = config('media.video');
        $s = (int) $c['short_side'];
        $out = $this->tmp('mp4');
        // Petit côté ramené à 720 (jamais agrandi), dimensions paires, 30 images/s au plus
        $scale = "scale='if(lt(iw,ih),min({$s},iw),-2)':'if(lt(iw,ih),-2,min({$s},ih))',fps='min({$c['fps']},source_fps)'";
        $ok = $this->run(['-i', $in, '-map', '0:v:0', '-map', '0:a:0?', '-vf', $scale, '-c:v', 'libx264', '-preset', $c['preset'], '-crf', (string) $c['crf'],
            '-profile:v', 'main', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', $c['audio_kbps'].'k', '-ac', '2', '-movflags', '+faststart', '-max_muxing_queue_size', '1024', $out]);
        if (! $ok) {
            // ffmpeg trop ancien pour « source_fps » : sans limite d'images/s
            $scale = "scale='if(lt(iw,ih),min({$s},iw),-2)':'if(lt(iw,ih),-2,min({$s},ih))'";
            $ok = $this->run(['-i', $in, '-map', '0:v:0', '-map', '0:a:0?', '-vf', $scale, '-c:v', 'libx264', '-preset', $c['preset'], '-crf', (string) $c['crf'],
                '-profile:v', 'main', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', $c['audio_kbps'].'k', '-ac', '2', '-movflags', '+faststart', $out]);
        }
        if (! $ok) {
            return null;
        }
        $info = \App\Support\MediaDuration::dimensions($out) ?: [];
        // Image d'aperçu : la vidéo n'est téléchargée que si on la lance
        $poster = $this->tmp('jpg');
        if ($this->run(['-ss', '0.3', '-i', $out, '-frames:v', '1', '-q:v', '5', $poster]) && is_file($poster)) {
            $info['poster_file'] = $poster;
        }

        return [$out, 'mp4', $info];
    }

    /** @return array{0: string, 1: string, 2: array}|null */
    private function audio(string $in): ?array
    {
        $out = $this->tmp('m4a');
        $ok = $this->run(['-i', $in, '-vn', '-c:a', 'aac', '-b:a', config('media.audio_kbps').'k', '-movflags', '+faststart', $out]);

        return $ok ? [$out, 'm4a', []] : null;
    }

    /** @return array{0: string, 1: string, 2: array}|null */
    private function image(string $in): ?array
    {
        $info = @getimagesize($in);
        if (! $info || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        // GIF animé : on ne touche pas
        if ($info[2] === IMAGETYPE_GIF) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($in));
        if (! $img) {
            return null;
        }
        // Photo de téléphone tournée : on applique l'orientation EXIF
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($in)['Orientation'] ?? 1);
            $img = match ($o) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => $img,
            };
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $max = (int) config('media.image.max');
        $ratio = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        // Fond blanc pour les PNG transparents passés en JPEG
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $out = $this->tmp('jpg');
        imageinterlace($dst, true);
        imagejpeg($dst, $out, (int) config('media.image.quality'));
        imagedestroy($img);
        imagedestroy($dst);

        return [$out, 'jpg', ['width' => $nw, 'height' => $nh]];
    }
}
