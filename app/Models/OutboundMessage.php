<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un SMS ou message WhatsApp envoyé (ou raté), avec les canaux essayés. Les codes y sont masqués. */
class OutboundMessage extends Model
{
    protected $fillable = ['to', 'purpose', 'body', 'channel', 'status', 'attempts', 'provider_ref'];

    protected $casts = ['attempts' => 'array'];
}
