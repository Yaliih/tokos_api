<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionInvitation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function invitee()
    {
        return $this->morphTo();
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** L'utilisateur peut-il répondre au nom de l'invité ? */
    public function canRespond(User $user): bool
    {
        if ($this->invitee_type === User::class) {
            return (int) $this->invitee_id === (int) $user->id;
        }
        if ($this->invitee_type === Space::class && $this->invitee) {
            return $this->invitee->canManage($user);
        }

        return false;
    }
}
