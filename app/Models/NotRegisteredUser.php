<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotRegisteredUser extends Model
{
    use HasFactory;
    protected $fillable = [
        'telegram_id'
    ];
}
