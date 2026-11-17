<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre les médias envoyés par l'app ou le web, au même format que les posts :
 *   media[i][file]     fichier (image, vidéo, audio)
 *   media[i][type]     image | video | audio (facultatif, détecté sinon)
 *   media[i][metadata] JSON facultatif, ex. {"peaks":[...],"duration":12.4} pour l'audio
 *   media[i][url]      chemin déjà stocké (réutilisation)
 */
class MediaUploader
{
    public const MAX_KB = 51200; // 50 Mo (valeur de départ ; l'admin la change : voir maxKb())

    /** Poids maximum d'un fichier en Ko, réglé dans l'admin (competition.media.max_upload_mb). */
    public static function maxKb(): int
    {
        return max(1, (int) config('competition.media.max_upload_mb', 50)) * 1024;
    }

    public static function rules(string $field = 'media'): array
    {
        return [
            $field => 'nullable|array|max:6',
            $field.'.*.file' => 'nullable|file|max:'.self::maxKb().'|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,audio/ogg,audio/webm,video/3gpp,application/octet-stream',
            $field.'.*.type' => 'nullable|in:image,video,audio',
            $field.'.*.metadata' => 'nullable',
            $field.'.*.url' => 'nullable|string|max:2048',
        ];
    }

    /**
     * @param  array{video?: int, audio?: int}  $max  durées maximales en secondes (par défaut : config competition.media)
     */
    public function storeMany(?array $items, string $folder, array $max = []): array
    {
        $result = [];
        foreach ($items ?? [] as $item) {
            $stored = $this->storeOne($item, $folder, $max);
            if ($stored) {
                $result[] = $stored;
            }
        }

        return $result;
    }

    public function storeOne(array $item, string $folder, array $max = []): ?array
    {
        $file = $item['file'] ?? null;
        $type = $item['type'] ?? null;
        $duration = null;

        if ($file instanceof UploadedFile) {
            // Le type vient toujours du contenu réel du fichier, jamais de ce que dit l'app ou le navigateur
            $detected = $this->detectType($file);
            if ($type && $type !== $detected) {
                $names = ['image' => 'une photo', 'video' => 'une vidéo', 'audio' => 'un audio'];
                throw ValidationException::withMessages(['media' => ['Ce fichier n\'est pas '.($names[$type] ?? $type).'.']]);
            }
            $type = $detected;
            if (in_array($type, ['audio', 'video'], true)) {
                $duration = $this->assertDuration($file, $type, $item['metadata'] ?? null, $max);
            }
            // Allègement (vidéo 720p H.264, audio AAC, photo 1600 px) avant de ranger le fichier
            [$toStore, $ext, $compressed] = app(MediaCompressor::class)->compress($file, $type);
            $disk = config('filesystems.media', 'public');
            $dir = $folder.'/'.now()->format('Y/m');
            $name = Str::random(24);
            $path = \Illuminate\Support\Facades\Storage::disk($disk)->putFileAs($dir, $toStore, $name.'.'.$ext);
            if ($toStore instanceof \Illuminate\Http\File) {
                @unlink($toStore->getRealPath());
            }
            if (! empty($compressed['poster_file'])) {
                $thumbnail = \Illuminate\Support\Facades\Storage::disk($disk)->putFileAs($dir, new \Illuminate\Http\File($compressed['poster_file']), $name.'.jpg');
                @unlink($compressed['poster_file']);
            }
            unset($compressed['poster_file']);
        } elseif (! empty($item['url']) && ! str_contains($item['url'], '..')) {
            $path = $item['url'];
            $type ??= 'image';
        } else {
            return null;
        }

        $media = ['type' => $type, 'url' => $path];
        if (! empty($thumbnail)) {
            $media['thumbnail'] = $thumbnail;
        }
        if (! empty($compressed['width'])) {
            $media['metadata'] = ['width' => (int) $compressed['width'], 'height' => (int) $compressed['height']];
        }
        if ($duration) {
            $media['metadata'] = array_merge($media['metadata'] ?? [], ['duration' => round($duration, 1)]);
        }

        $metadata = $item['metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        if (is_array($metadata)) {
            $clean = [];
            if (isset($metadata['duration'])) {
                $clean['duration'] = (float) $metadata['duration'];
            }
            if (isset($metadata['peaks'])) {
                $peaks = is_string($metadata['peaks']) ? json_decode($metadata['peaks'], true) : $metadata['peaks'];
                $clean['peaks'] = array_slice(array_map('floatval', (array) $peaks), 0, 400);
            }
            if (isset($metadata['width'], $metadata['height'])) {
                $clean['width'] = (int) $metadata['width'];
                $clean['height'] = (int) $metadata['height'];
            }
            if ($clean) {
                $media['metadata'] = array_merge($clean, $media['metadata'] ?? []);
            }
        }

        // Vidéo : largeur et hauteur, pour l'afficher tout de suite dans son format
        if ($type === 'video' && empty($media['metadata']['width']) && $file instanceof UploadedFile) {
            if ($dims = \App\Support\MediaDuration::dimensions($file->getRealPath())) {
                $media['metadata'] = array_merge($media['metadata'] ?? [], $dims);
            }
        }

        return $media;
    }

    /**
     * Refuse un audio ou une vidéo trop longs. La durée est lue sur le serveur ;
     * à défaut, celle envoyée par l'app ou le navigateur est utilisée.
     */
    public function assertDuration(UploadedFile $file, string $type, mixed $metadata = null, array $max = []): ?float
    {
        $limit = (int) ($max[$type] ?? config('competition.media.max_'.$type.'_seconds', $type === 'video' ? 180 : 300));
        $seconds = \App\Support\MediaDuration::seconds($file->getRealPath());
        if ($seconds === null) {
            $meta = is_string($metadata) ? json_decode($metadata, true) : $metadata;
            $seconds = is_array($meta) && isset($meta['duration']) ? (float) $meta['duration'] : null;
        }
        if ($seconds !== null && $limit > 0 && $seconds > $limit + 1) {
            throw ValidationException::withMessages(['media' => [
                ($type === 'video' ? 'Vidéo' : 'Audio').' trop longue : '.\App\Support\MediaDuration::label((int) $limit).' maximum.',
            ]]);
        }

        return $seconds;
    }

    private function detectType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());

        // WebM (souvent un enregistrement fait dans le navigateur) : on regarde s'il y a une piste vidéo
        if ($ext === 'webm' || \App\Support\MediaDuration::isMatroska($file->getRealPath())) {
            return \App\Support\MediaDuration::matroskaHasVideo($file->getRealPath()) ? 'video' : 'audio';
        }
        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'm4a', 'wav', 'ogg', 'aac'], true)) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', '3gp'], true)) {
            return 'video';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        throw ValidationException::withMessages(['media' => ['Type de fichier non pris en charge.']]);
    }
}
