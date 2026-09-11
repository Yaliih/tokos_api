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

class User extends Authenticatable
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
        'website',
        'is_private',
        'is_active',
        'username_changed',
        'last_login_at',
        'last_login_ip',
        'phone_verification_code',
        'phone_verified_at',
        'phone_verification_expires_at',
        'verified_at'
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
        'username_changed' => 'boolean',
    ];
    protected $appends = ['cover', 'avatar','avatar_url','cover_url']; // Nom de l'attribut en snake_case

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

    // Génération username unique
    public static function generateUniqueUsername(string $firstName, string $lastName): string
    {
        $base = strtolower(
            substr(preg_replace('/[^a-zA-Z0-9]/', '', $firstName), 0, 1) .
                preg_replace('/[^a-zA-Z0-9]/', '', $lastName)
        );

        $username = $base;
        $counter = 1;

        while (static::withTrashed()->where('username', $username)->exists()) {
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
       
        switch ($attribute) {
            case 'cover':
                $link = $brute != null ? $brute : 'covers/images/null.png';
                break;
            case 'avatar':
                $link = $brute != null ? $brute : ($this->gender == 'male' ? 'avatars/null.png' : 'avatars/nulle.png');
                break;
            default:
                $link = 'images/' . $this->photo;
        }




        $url = route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&',  $link)));
        return $url;
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
}
