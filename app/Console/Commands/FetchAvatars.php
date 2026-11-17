<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FetchAvatars extends Command
{
    protected $signature = 'avatars:fetch
                            {--count=12 : Nombre d\'avatars à télécharger}
                            {--style=avataaars : Style DiceBear}';

    protected $description = 'Télécharge les avatars DiceBear dans storage/app/public/avatars';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $style = $this->option('style');

        Storage::disk(config('filesystems.media', 'public'))->makeDirectory('avatars');

        $this->info("Téléchargement de {$count} avatars (style: {$style})...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 1; $i <= $count; $i++) {
            $seed = "avatar{$i}";
            $url = "https://api.dicebear.com/7.x/{$style}/png?seed={$seed}&size=400";

            try {
                $response = Http::timeout(15)->get($url);

                if ($response->successful()) {
                    $filename = "avatars/{$seed}.png";
                    Storage::disk(config('filesystems.media', 'public'))->put($filename, $response->body());
                } else {
                    $this->newLine();
                    $this->warn("Échec pour {$seed} (HTTP {$response->status()})");
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Erreur pour {$seed} : {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Terminé. Fichiers dans storage/app/public/avatars/");
        $this->info("   Accessibles via : " . url('/storage/avatars/avatar1.png'));

        return self::SUCCESS;
    }
}