<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    protected $fillable = [
        'user_id',
        'file_id',
        'file_type',
        'processed'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
