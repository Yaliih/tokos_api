<?php

namespace App\Support;

use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Représentation commune d'un "acteur" : utilisateur ou Espace.
 * Utilisée partout (API, web, fil d'activité) pour un affichage homogène.
 */
class Participant
{
    public static function type(?Model $model): ?string
    {
        return match (true) {
            $model instanceof User => 'user',
            $model instanceof Space => 'space',
            default => null,
        };
    }

    private static function real(mixed $path): bool
    {
        return is_string($path) && $path !== '' && ! str_contains($path, 'null');
    }

    public static function present(?Model $model): ?array
    {
        if ($model instanceof User) {
            return [
                'type' => 'user',
                'id' => $model->id,
                'name' => trim($model->first_name.' '.$model->last_name),
                'handle' => $model->username,
                'avatar_url' => $model->avatar_url,
                'has_avatar' => self::real($model->getRawOriginal('avatar')), // une vraie photo (pas l'image par défaut) : on peut l'ouvrir en grand
                'is_verified' => $model->verified_at !== null,
                'url' => '/@'.$model->username,
            ];
        }

        if ($model instanceof Space) {
            return [
                'type' => 'space',
                'id' => $model->id,
                'name' => $model->name,
                'handle' => $model->slug,
                'avatar_url' => $model->avatar_url,
                'has_avatar' => self::real($model->getRawOriginal('avatar')),
                'is_verified' => (bool) $model->is_verified,
                'url' => '/espaces/'.$model->slug,
            ];
        }

        return null;
    }

    public static function name(?Model $model): string
    {
        return self::present($model)['name'] ?? 'Quelqu\'un';
    }

    public static function fromTypeAndId(?string $type, $id): ?Model
    {
        return match ($type) {
            'user', User::class => User::find($id),
            'space', Space::class => Space::find($id),
            default => null,
        };
    }
}
