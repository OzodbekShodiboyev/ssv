<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSession extends Model
{
    protected $fillable = [
        'telegram_id',
        'step',
        'data'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    // Helper metodlar
    public static function getStep($telegramId)
    {
        $session = self::where('telegram_id', $telegramId)->first();
        return $session ? $session->step : null;
    }

    public static function setStep($telegramId, $step, $data = [])
    {
        return self::updateOrCreate(
            ['telegram_id' => $telegramId],
            ['step' => $step, 'data' => $data]
        );
    }

    public static function getData($telegramId, $key = null)
    {
        $session = self::where('telegram_id', $telegramId)->first();

        if (!$session || !$session->data) {
            return null;
        }

        if ($key) {
            return $session->data[$key] ?? null;
        }

        return $session->data;
    }

    public static function clearSession($telegramId)
    {
        return self::where('telegram_id', $telegramId)->delete();
    }
}
