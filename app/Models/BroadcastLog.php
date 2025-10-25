<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastLog extends Model
{
    protected $fillable = [
        'broadcast_id',
        'telegram_id',
        'success',
        'error',
        'sent_at'
    ];

    protected $casts = [
        'success' => 'boolean',
        'sent_at' => 'datetime'
    ];

    public function broadcast()
    {
        return $this->belongsTo(Broadcast::class);
    }
}
