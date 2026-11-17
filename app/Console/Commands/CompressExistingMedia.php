<?php

namespace App\Console\Commands;

use App\Models\CompetitionEntry;
use App\Models\EntryProposal;
use App\Models\Space;
use App\Models\User;
use App\Services\MediaCompressor;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Allège les médias déjà envoyés (participations, propositions, photos de profil, couvertures). */
class CompressExistingMedia extends Command
{
    protected $signature = 'media:compresser {--limite=0 : nombre maximum de fichiers}';

    protected $description = 'Compresser les vidéos, audios et photos déjà en ligne';

    private int $done = 0;

    private int $saved = 0;

    public function handle(MediaCompressor $compressor): int
    {
        if (! $compressor->ffmpegAvailable()) {
            $this->warn('ffmpeg introuvable : seules les photos seront allégées. (FFMPEG_BINARY dans .env)');
        }
        $limit = (int) $this->option('limite');

        foreach ([CompetitionEntry::class, EntryProposal::class] as $class) {
            $class::whereNotNull('media')->orderBy('id')->each(function ($record) use ($compressor, $limit) {
                if ($limit && $this->done >= $limit) {
                    return false;
                }
                $media = $record->media ?? [];
                $changed = false;
                foreach ($media as $i => $item) {
                    if (! is_array($item) || empty($item['url']) || ! empty($item['metadata']['compressed'])) {
                        continue;
                    }
                    if ($new = $this->compressPath($compressor, $item['url'], $item['type'] ?? 'image')) {
                        $media[$i] = array_merge($item, ['url' => $new['url']], isset($new['thumbnail']) ? ['thumbnail' => $new['thumbnail']] : []);
                        $media[$i]['metadata'] = array_merge($item['metadata'] ?? [], $new['metadata'], ['compressed' => true]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $record->forceFill(['media' => $media])->saveQuietly();
                }
            });
        }

        foreach ([User::class, Space::class] as $class) {
            $class::query()->orderBy('id')->each(function ($model) use ($compressor, $limit) {
                if ($limit && $this->done >= $limit) {
                    return false;
                }
                $avatar = $model->getRawOriginal('avatar');
                if ($avatar && ! str_contains($avatar, 'null') && ($new = $this->compressPath($compressor, $avatar, 'image'))) {
                    $model->forceFill(['avatar' => $new['url']])->saveQuietly();
                }
                $cover = $model->getRawOriginal('cover');
                if ($cover && ! str_contains($cover, 'null') && ($new = $this->compressPath($compressor, $cover, ($model->cover_type ?? 'image') === 'video' ? 'video' : 'image'))) {
                    $model->forceFill(['cover' => $new['url']])->saveQuietly();
                }
            });
        }

        $this->info($this->done.' fichier(s) allégé(s), '.round($this->saved / 1048576, 1).' Mo gagnés.');

        return self::SUCCESS;
    }

    /** @return array{url: string, thumbnail?: string, metadata: array}|null */
    private function compressPath(MediaCompressor $compressor, string $path, string $type): ?array
    {
        $disk = Storage::disk(config('filesystems.media', 'public'));
        if (filter_var($path, FILTER_VALIDATE_URL) || str_contains($path, '..') || str_starts_with($path, 'demo/') || ! $disk->exists($path)) {
            return null;
        }
        $size = (int) $disk->size($path);
        // Petites photos déjà légères : rien à faire
        if ($type === 'image' && $size < 300 * 1024) {
            return null;
        }
        $local = tempnam(sys_get_temp_dir(), 'tokos_src_').'.'.pathinfo($path, PATHINFO_EXTENSION);
        file_put_contents($local, $disk->get($path));
        $upload = new UploadedFile($local, basename($path), null, null, true);
        [$result, $ext, $info] = $compressor->compress($upload, $type);
        if (! $result instanceof File) {
            @unlink($local);

            return null;
        }
        $dir = trim(dirname($path), '.');
        $name = Str::random(24);
        $newPath = $disk->putFileAs($dir, $result, $name.'.'.$ext);
        $out = ['url' => $newPath, 'metadata' => array_filter(['width' => $info['width'] ?? null, 'height' => $info['height'] ?? null])];
        if (! empty($info['poster_file'])) {
            $out['thumbnail'] = $disk->putFileAs($dir, new File($info['poster_file']), $name.'.jpg');
            @unlink($info['poster_file']);
        }
        $this->saved += max(0, $size - (int) filesize($result->getRealPath()));
        @unlink($result->getRealPath());
        @unlink($local);
        $disk->delete($path);
        $this->done++;
        $this->line('  '.$path.' → '.$newPath);

        return $out;
    }
}
