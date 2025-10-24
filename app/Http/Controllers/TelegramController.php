<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use App\Models\User;

class TelegramController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function handle(Request $request)
    {
        try {
            $update = $this->telegram->getWebhookUpdate();

            // Agar message bo'lmasa, return qil
            if (!$update->getMessage()) {
                return response()->json(['ok' => true]);
            }

            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = $message->getText();

            if (!$chatId) {
                return response()->json(['ok' => true]);
            }

            $user = User::where('telegram_id', $chatId)->first();

            // /start command
            if ($text == '/start') {
                if ($user) {
                    $this->sendMainMenu($chatId);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "👋 Assalomu alaykum! Iltimos, to'liq ism-familiyangizni kiriting:",
                    ]);
                    cache()->put("step_$chatId", 'ask_name', now()->addMinutes(10));
                }
                return response()->json(['ok' => true]);
            }

            $step = cache()->get("step_$chatId");

            // Ism so'rash qadami
            if ($step == 'ask_name') {
                cache()->put("name_$chatId", $text, now()->addMinutes(10));
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "📞 Endi telefon raqamingizni yuboring (yoki pastdagi tugma orqali ulashing):",
                    'reply_markup' => json_encode([
                        'keyboard' => [[['text' => '📲 Telefon raqamni ulashish', 'request_contact' => true]]],
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true,
                    ]),
                ]);
                cache()->put("step_$chatId", 'ask_phone', now()->addMinutes(10));
                return response()->json(['ok' => true]);
            }

            // Telefon raqam so'rash qadami
            if ($step == 'ask_phone') {
                $contact = $message->getContact();
                $phone = $contact ? $contact->getPhoneNumber() : $text;

                $name = cache()->pull("name_$chatId");

                User::create([
                    'telegram_id' => $chatId,
                    'name' => $name,
                    'phone' => $phone,
                ]);

                cache()->forget("step_$chatId");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Ro'yxatdan muvaffaqiyatli o'tdingiz!\n\n💳 Kursga yozilish uchun quyidagi kartaga to'lov qiling:\n\n💳 *8600 1234 5678 9012*\n\nTo'lovdan so'ng administratorga yozing.",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode([
                        'remove_keyboard' => true
                    ])
                ]);

                // Asosiy menyuni ko'rsat
                $this->sendMainMenu($chatId);

                return response()->json(['ok' => true]);
            }

            // Agar user ro'yxatdan o'tgan bo'lsa
            if ($user) {
                $this->handleUserMessage($chatId, $text);
            }

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            \Log::error('Telegram webhook error: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    protected function handleUserMessage($chatId, $text)
    {
        // Klaviatura tugmalari uchun
        if (strpos($text, '1️⃣') !== false || strpos($text, 'Kursga yozilish') !== false) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "📚 Kurslar ro'yxati:\n\n1. Frontend Development\n2. Backend Development\n3. Mobile Development\n\nQaysi kursga yozilmoqchisiz?",
            ]);
            return;
        }

        if (strpos($text, '2️⃣') !== false || strpos($text, 'Savollar') !== false) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❓ Savolingizni yozing, tez orada javob beramiz!",
            ]);
            return;
        }

        if (strpos($text, '3️⃣') !== false || strpos($text, 'Qo\'llab-quvvatlash') !== false) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "📞 Qo'llab-quvvatlash:\n\n📧 Email: support@example.com\n📱 Telefon: +998 90 123 45 67\n\nYoki savolingizni shu yerga yozing!",
            ]);
            return;
        }

        // Default javob
        $this->sendMainMenu($chatId);
    }

    protected function sendMainMenu($chatId)
    {
        $keyboard = [
            ['1️⃣ Kursga yozilish'],
            ['2️⃣ Savollar', '3️⃣ Qo\'llab-quvvatlash']
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "📋 Asosiy menyu:\n\nQuyidagi bo'limlardan birini tanlang:",
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
            ])
        ]);
    }

    // Webhook o'rnatish uchun (faqat bir marta ishlatish)
    public function setWebhook()
    {
        $url = url('/telegram/webhook');
        $response = $this->telegram->setWebhook(['url' => $url]);

        return response()->json([
            'success' => true,
            'result' => $response
        ]);
    }

    // Webhook ma'lumotlarini olish
    public function getWebhookInfo()
    {
        $response = $this->telegram->getWebhookInfo();
        return response()->json($response);
    }
}
