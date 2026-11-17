<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un jour où une personne est venue (voir TrackVisit). */
class UserVisit extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'day', 'hits', 'source'];

    protected $casts = ['day' => 'date'];
}
