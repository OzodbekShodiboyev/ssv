<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    protected $fillable = [
        'message',
        'total_users',
        'sent_count',
        'failed_count',
        'completed'
    ];

    protected $casts = [
        'completed' => 'boolean'
    ];

    public function logs()
    {
        return $this->hasMany(BroadcastLog::class);
    }
}
