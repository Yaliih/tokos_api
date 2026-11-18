<?php

namespace App\Models;

use App\Models\Preference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

// Tant que Filament n'est pas installé, ses deux interfaces existent quand même (vides) : le site continue de marcher.
if (! interface_exists(\Filament\Models\Contracts\FilamentUser::class)) {
    eval('namespace Filament\Models\Contracts; interface FilamentUser {} interface HasName {}');
}

class User extends Authenticatable implements \Filament\Models\Contracts\FilamentUser, \Filament\Models\Contracts\HasName
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'phone_code',
        'phone_number',
        'email',
        'password',
        'birth_date',
        'avatar',
        'cover',
        'photo',
        'cover_type',
        'bio',
        'gender',
        'country',
        'city',
        'school_space_id',
        'website',
        'is_private',
        'is_active',
        'is_admin',
        'username_changed',
        'last_login_at',
        'last_login_ip',
        'phone_verification_code',
        'phone_verified_at',
        'phone_verification_expires_at',
        'verified_at',
        'last_seen_at',
        'notification_settings',
        'interests',
        'interests_asked_at',
        'city_located_at',
        'city_asked_at',
        'duels_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'phone_verification_code',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'phone_verified_at' => 'datetime',
        'phone_verification_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_private' => 'boolean',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',
        'verified_at' => 'datetime',
        'username_changed' => 'boolean',
        'last_seen_at' => 'datetime',
        'notification_settings' => 'array',
        'interests' => 'array',
        'interests_asked_at' => 'datetime',
        'city_located_at' => 'datetime',
        'city_asked_at' => 'datetime',
        'duels_seen_at' => 'datetime',
    ];
    protected $appends = ['cover', 'avatar','avatar_url','cover_url']; // Nom de l'attribut en snake_case

    /** Son institution d'enseignement (un Espace de type « school »). */
    public function school()
    {
        return $this->belongsTo(Space::class, 'school_space_id');
    }

    /** Les jours où la personne est venue. */
    public function visits()
    {
        return $this->hasMany(UserVisit::class);
    }

    /** Seuls les comptes admin entrent dans /admin. */
    public function canAccessPanel($panel): bool
    {
        return (bool) $this->is_admin && $this->is_active !== false;
    }

    public function getFilamentName(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: (string) $this->username;
    }

    // Boot : génération automatique du username
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->username)) {
                $user->username = self::generateUniqueUsername(
                    $user->first_name,
                    $user->last_name
                );
            }
        });
    }

    /** Ancien pseudo de quelqu'un : personne d'autre ne peut le prendre. */
    public static function usernameReserved(string $username, ?int $exceptUserId = null): bool
    {
        return \Illuminate\Support\Facades\DB::table('username_history')
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
            ->when($exceptUserId, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->exists();
    }

    /** Un ancien pseudo : la personne qui l'avait (pour rediriger vers son profil actuel). */
    public static function byOldUsername(string $username): ?self
    {
        $id = \Illuminate\Support\Facades\DB::table('username_history')->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->value('user_id');

        return $id ? static::find($id) : null;
    }

    /** Le pseudo ne se change qu'une seule fois (le premier est créé à l'inscription). */
    public function canChangeUsername(): bool
    {
        return ! $this->username_changed;
    }

    // Génération username unique
    public static function generateUniqueUsername(string $firstName, string $lastName): string
    {
        $base = strtolower(
            substr(preg_replace('/[^a-zA-Z0-9]/', '', $firstName), 0, 1) .
                preg_replace('/[^a-zA-Z0-9]/', '', $lastName)
        );

        $username = $base;
        $counter = 1;

        // Ni un pseudo actuel, ni l'ancien pseudo de quelqu'un (réservé pour toujours)
        while (static::withTrashed()->where('username', $username)->exists() || static::usernameReserved($username)) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    // Relations
    public function preferences()
    {
        return $this->belongsToMany(Preference::class)->withTimestamps();
    }

    // Accesseurs
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getFullPhoneAttribute(): string
    {
        return "{$this->phone_code}{$this->phone_number}";
    }

    public function getAvatarUrlAttribute(): string
    {
            return $this->urlt('avatar');
    }

    // Vérification téléphone
    public function hasVerifiedPhone(): bool
    {
        return !is_null($this->phone_verified_at);
    }

    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill([
            'phone_verified_at' => now(),
            'phone_verification_code' => null,
            'phone_verification_expires_at' => null,
        ])->save();
    }

    public function generateVerificationCode(): string
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'phone_verification_code' => $code,
            'phone_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    protected function avatar(): Attribute
    {
        return Attribute::make(

            get: fn() => $this->urlt('avatar'),
        );
    }
    
    protected function getCoverUrlAttribute(): Attribute
    {
        return Attribute::make(

            get: fn() => $this->urlt('cover'),
        );
    }
    protected function cover(): Attribute
    {
        return Attribute::make(

            get: fn() => $this->urlt('cover'),
        );
    }
    public function urlt(string $attribute)
    {
        
        // On utilise getRawOriginal($attribute) pour éviter le conflit de nom
        $brute = $this->getRawOriginal($attribute);
       
        // Photos par défaut : des fichiers du site (public/img/…), donc versionnés et présents
        // sur toutes les installations. Celles du stockage étaient ignorées par git.
        if ($brute === null || $brute === '') {
            if ($attribute === 'cover') {
                return asset('img/covers/defaut.png');
            }
            if ($attribute === 'avatar') {
                return asset($this->gender === 'male' ? 'img/avatars/homme.png' : 'img/avatars/femme.png');
            }
        }

        switch ($attribute) {
            case 'cover':
            case 'avatar':
                $link = (string) $brute;
                break;
            default:
                $link = 'images/' . $this->photo;
        }




        return \App\Support\MediaUrl::make($link);
    }

    public function isValidVerificationCode(string $code): bool
    {
        if (!$this->phone_verification_code || !$this->phone_verification_expires_at) {
            return false;
        }

        if ($this->phone_verification_expires_at->isPast()) {
            return false;
        }

        return $this->phone_verification_code === $code;
    }

    // Vérification mot de passe pour login
    public function validatePassword(string $password): bool
    {
        return Hash::check($password, $this->password);
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')
            ->wherePivot('is_accepted', true)
            ->withTimestamps();
    }

    public function following()
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')
            ->wherePivot('is_accepted', true)
            ->withTimestamps();
    }

    public function followRequests()
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')
            ->wherePivot('is_accepted', false)
            ->withPivot('created_at')
            ->withTimestamps();
    }

    public function isFollowing(User $user): bool
    {
        return $this->following()->where('users.id', $user->id)->exists();
    }

    public function isFollowedBy(User $user): bool
    {
        return $this->followers()->where('users.id', $user->id)->exists();
    }

    public function follow(User $user): void
    {
        if ($this->id === $user->id) return;

        $this->following()->syncWithoutDetaching([$user->id => ['is_accepted' => true, 'accepted_at' => now()]]);
    }

    public function unfollow(User $user): void
    {
        $this->following()->detach($user->id);
    }

    public function ownedSpaces()
    {
        return $this->hasMany(Space::class, 'owner_id');
    }

    public function managedSpaces()
    {
        return $this->belongsToMany(Space::class, 'space_managers')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function joinedSpaces()
    {
        return $this->belongsToMany(Space::class, 'space_members')
            ->withPivot('is_accepted', 'accepted_at')
            ->wherePivot('is_accepted', true)
            ->withTimestamps();
    }

    // ── Compétitions & couche sociale ───────────────────────────

    public function organizedCompetitions()
    {
        return $this->morphMany(Competition::class, 'organizer');
    }

    public function competitionEntries()
    {
        return $this->morphMany(CompetitionEntry::class, 'participant');
    }

    public function ratings()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function badges()
    {
        return $this->morphMany(Badge::class, 'owner');
    }

    public function activities()
    {
        return $this->morphMany(Activity::class, 'actor');
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Ids de tous les Espaces auxquels l'utilisateur est lié (propriétaire, gestionnaire ou membre).
     */
    public function allSpaceIds(): array
    {
        return Space::query()
            ->where('owner_id', $this->id)
            ->orWhereHas('managers', fn ($q) => $q->where('users.id', $this->id))
            ->orWhereHas('members', fn ($q) => $q->where('users.id', $this->id))
            ->pluck('id')
            ->all();
    }

    /** Ids des Espaces que l'utilisateur peut représenter (propriétaire, admin, éditeur). */
    /** Espaces au nom desquels je peux publier (administrateur, modérateur ou publieur). */
    public function publishableSpaceIds(): array
    {
        return Space::query()
            ->where('owner_id', $this->id)
            ->orWhereHas('managers', fn ($q) => $q->where('users.id', $this->id)->whereIn('space_managers.role', ['admin', 'editor', 'viewer']))
            ->pluck('id')
            ->all();
    }

    public function manageableSpaceIds(): array
    {
        return Space::query()
            ->where('owner_id', $this->id)
            ->orWhereHas('managers', fn ($q) => $q->where('users.id', $this->id)->whereIn('space_managers.role', ['admin', 'editor']))
            ->pluck('id')
            ->all();
    }
}
