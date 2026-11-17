<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Réglage stocké en base (clé / valeur JSON), modifié depuis l'admin. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];
}
