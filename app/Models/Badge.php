<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'data' => 'array',
        'awarded_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->morphTo();
    }

    public function definition(): array
    {
        return config('badges.'.$this->code, ['name' => $this->code, 'emoji' => '🏅', 'description' => '']);
    }
}
