<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaxAudioDuration implements ValidationRule
{
    protected $maxSeconds;

    public function __construct(int $maxSeconds = 120)
    {
        $this->maxSeconds = $maxSeconds;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // $value est une instance de UploadedFile
        if (!$value->isValid()) {
            $fail("Le fichier audio n'est pas valide.");
            return;
        }

        // Utilisation de getID3 pour lire la durée
        $getID3 = new \getID3();
        $fileInfo = $getID3->analyze($value->getRealPath());

        $duration = $fileInfo['playtime_seconds'] ?? 0;

        if ($duration > $this->maxSeconds) {
            $fail("La durée de l'audio ne doit pas dépasser {$this->maxSeconds} secondes.");
        }
    }
}