<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reaction extends Model
{
    public const EMOJIS = ['🔥', '😂', '😮', '👏', '💯', '❤️'];

    protected $guarded = ['id'];

    public function reactable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
