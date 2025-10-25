<?php

namespace App\Http\Controllers;

use App\Models\NotRegisteredUser;
use Illuminate\Http\Request;
use Telegram\Bot\Api;
use App\Models\User;
use App\Models\TelegramSession;
use Mockery\Matcher\Not;

class TelegramController extends Controller
{
    protected $telegram;
    const ADMIN_CHAT_ID = '925986011'; // Admin telegram ID sini kiriting

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function handle(Request $request)
    {
        try {
            $update = $this->telegram->getWebhookUpdate();
            $updateId = $update->getUpdateId();

            // Update ID ni cache da tekshirish (5 daqiqa)
            $cacheKey = "telegram_update_{$updateId}";
            if (cache()->has($cacheKey)) {
                \Log::info('Duplicate update ignored', ['update_id' => $updateId]);
                return response()->json(['ok' => true]);
            }

            // Cache ga saqlash
            cache()->put($cacheKey, true, now()->addMinutes(5));

            if (!$update->getMessage()) {
                // Callback query (inline button bosilganda)
                if ($update->getCallbackQuery()) {
                    $this->handleCallbackQuery($update->getCallbackQuery());
                }
                return response()->json(['ok' => true]);
            }

            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = $message->getText();
            $photo = $message->getPhoto();
            $document = $message->getDocument();

            if (!$chatId) {
                return response()->json(['ok' => true]);
            }

            $user = User::where('telegram_id', $chatId)->first();
            if ($chatId == self::ADMIN_CHAT_ID) {
                // /stat - Statistika
                if ($text == '/stat') {
                    $this->showStatistics($chatId);
                    return response()->json(['ok' => true]);
                }

                // /reklama - Reklama yuborish
                if ($text == '/reklama') {
                    TelegramSession::setStep($chatId, 'waiting_broadcast_message');
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "📢 <b>Reklama xabarini yuboring:</b>\n\n" .
                            "✏️ Matnni, rasmni yoki dokumentni yuborishingiz mumkin.\n\n" .
                            "❌ Bekor qilish uchun: /bekor",
                        'parse_mode' => 'HTML'
                    ]);
                    return response()->json(['ok' => true]);
                }

                // /bekor - Bekor qilish
                if ($text == '/bekor') {
                    $step = TelegramSession::getStep($chatId);
                    if ($step == 'waiting_broadcast_message') {
                        TelegramSession::clearSession($chatId);
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "❌ Reklama yuborish bekor qilindi."
                        ]);
                        return response()->json(['ok' => true]);
                    }
                }

                // Agar admin reklama matnini yuborsa
                $adminStep = TelegramSession::getStep($chatId);
                if ($adminStep == 'waiting_broadcast_message') {
                    $this->handleBroadcastMessage($chatId, $message, $text, $photo, $document);
                    return response()->json(['ok' => true]);
                }
            }
            // /start command
            if ($text == '/start') {
                if ($user) {
                    TelegramSession::clearSession($chatId);
                    $this->sendMainMenu($chatId);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "<b>Assalomu alaykum!</b>\n\n" .
                            "📝 <i>Ro'yxatdan o'tish uchun, to'liq ism-familiyangizni kiriting:</i>",
                        'parse_mode' => 'HTML'
                    ]);
                    TelegramSession::setStep($chatId, 'ask_name');
                    NotRegisteredUser::create([
                        'telegram_id' => $chatId
                    ]);
                }
                return response()->json(['ok' => true]);
            }

            // Sessiyadan step olish
            $step = TelegramSession::getStep($chatId);

            // Ism so'rash qadami
            if ($step == 'ask_name' && $text) {
                TelegramSession::setStep($chatId, 'ask_phone', ['name' => $text]);

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "📞 <b>Doimiy ishlaydigan telefon raqamingizni yuboring:</b>\n\n" .
                        "<i>(Agar telfon raqamingiz telegram raqamingiz bilan bir xil bo'lsa pastdagi tugma orqali yuboring!)</i>",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'keyboard' => [[['text' => '📲 Telefon raqamni yuborish', 'request_contact' => true]]],
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true,
                    ]),
                ]);

                return response()->json(['ok' => true]);
            }

            // Telefon raqam so'rash qadami
            if ($step == 'ask_phone') {
                $contact = $message->getContact();

                if (!$contact && !$text) {
                    return response()->json(['ok' => true]);
                }

                $phone = $contact ? $contact->getPhoneNumber() : $text;
                $name = TelegramSession::getData($chatId, 'name');

                if (!$name) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "❌ Sessiya tugadi. /start bosing.",
                    ]);
                    TelegramSession::clearSession($chatId);
                    return response()->json(['ok' => true]);
                }

                // Userga saqlash
                User::create([
                    'telegram_id' => $chatId,
                    'name' => $name,
                    'phone' => $phone,
                ]);

                TelegramSession::clearSession($chatId);

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❗️<b>Malaka oshirish kursiga qo'shilish uchun oxirgi qadam:</b>\n\n" .
                        "📝 Ismingiz: <code>$name</code>\n" .
                        "📞 Telefon: <code>$phone</code>\n\n" .
                        "💳 <b>To'lov rekvizitlari:</b>\n" .
                        "🔹 UzCard: <code>6262 4700 5443 3169</code>\n" .
                        "🔹 Humo: <code>9860 3501 1851 8355</code>\n\n" .
                        "📋 <b>To'lovni amalga oshirish tartibi:</b>\n\n" .
                        "1️⃣ Yuqoridagi kartalardan biriga 200 000 (ikki yuz ming) so'm to'lovni Click, Payme, UzumBank, Zumrad kabi ilovalar orqali (kartadan-kartaga) yoki Paynet shaxobchalari orqali amalga oshiring ✅\n\n" .
                        "2️⃣ To'lov qilgandan so'ng <b>screenshot qilib mana shu botga yuboring</b> ✅\n" .
                        "   <i>(Screenshot'da summa, sana va vaqt ko'rinishi shart)</i>\n\n" .
                        "3️⃣ To'lovingizni 30 daqiqa ichida ko'rib chiqib, siz bilan bog'lanamiz ✅",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'remove_keyboard' => true
                    ])
                ]);

                return response()->json(['ok' => true]);
            }

            // Agar user ro'yxatdan o'tgan bo'lsa va rasm/file yuborsa
            if ($user && ($photo || $document)) {
                $this->handlePaymentReceipt($user, $message, $photo, $document);
                return response()->json(['ok' => true]);
            }

            // Agar user ro'yxatdan o'tgan bo'lsa
            if ($user) {
                $this->handleUserMessage($chatId, $text);
            } else if (!$step) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Ro'yxatdan o'tish uchun /start bosing.",
                ]);
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            \Log::error('Telegram webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    protected function handlePaymentReceipt($user, $message, $photo, $document)
    {
        $chatId = $user->telegram_id;

        // Userga tasdiqlash xabari
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ To'lov cheki qabul qilindi!\n\n" .
                "⏳ Chekingiz ko'rib chiqilmoqda...\n" .
                "📞 Tez orada siz bilan bog'lanamiz (30 daqiqa ichida)",
        ]);

        // Adminga yuborish
        $adminMessage = "🔔 <b>Yangi to'lov cheki!</b>\n\n" .
            "👤 Ism: <b>{$user->name}</b>\n" .
            "📞 Telefon: <code>{$user->phone}</code>\n" .
            "🆔 User ID: <code>{$user->telegram_id}</code>\n" .
            "📅 Vaqt: " . now()->format('d.m.Y H:i');

        // Avval rasmni yuborish
        if ($photo) {
            // Collection bo‘lsa arrayga aylantiramiz
            $photoSizes = $photo instanceof \Illuminate\Support\Collection
                ? $photo->toArray()
                : (array) $photo;

            $biggest = count($photoSizes) > 0 ? end($photoSizes) : null;

            if (!$biggest || !isset($biggest['file_id'])) {
                \Log::error('Telegram photo object invalid - no file_id', ['photo' => $photoSizes]);
                return;
            }

            $fileId = $biggest['file_id'];

            $this->telegram->sendPhoto([
                'chat_id'  => (int) self::ADMIN_CHAT_ID,
                'photo'    => $fileId,
                'caption'  => $adminMessage,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Bog\'lanish', 'url' => "tg://user?id={$user->telegram_id}"]
                        ]
                    ]
                ])
            ]);
        } else if ($document) {
            $fileId = $document->getFileId();
            $this->telegram->sendDocument([
                'chat_id' => self::ADMIN_CHAT_ID,
                'document' => $fileId,
                'caption' => $adminMessage,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Bog\'lanish', 'url' => "tg://user?id={$user->telegram_id}"]
                        ]
                    ]
                ])
            ]);
        }

        \Log::info('Payment receipt sent to admin', ['user_id' => $user->id]);
    }

    protected function handleCallbackQuery($callbackQuery)
    {
        $data = $callbackQuery->getData();
        $callbackId = $callbackQuery->getId();
        $adminChatId = $callbackQuery->getMessage()->getChat()->getId();

        // "Bog'lanib bo'lmadi" tugmasi bosilganda
        if (strpos($data, 'cant_contact_') === 0) {
            $userChatId = str_replace('cant_contact_', '', $data);

            // Userga xabar yuborish
            $this->telegram->sendMessage([
                'chat_id' => $userChatId,
                'text' => "❌ <b>Sizga bog'lanishda muammo yuz berdi</b>\n\n" .
                    "📞 Iltimos, quyidagi telegram manzilga murojaat qiling:\n\n" .
                    "👉 @YourSupportUsername\n\n" .
                    "Yoki qo'ng'iroq qiling: <code>+998 90 123 45 67</code>",
                'parse_mode' => 'HTML'
            ]);

            // Adminga tasdiqlash
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text' => '✅ Foydalanuvchiga xabar yuborildi',
                'show_alert' => true
            ]);

            // Xabarni yangilash
            $this->telegram->editMessageReplyMarkup([
                'chat_id' => $adminChatId,
                'message_id' => $callbackQuery->getMessage()->getMessageId(),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Xabar yuborildi', 'callback_data' => 'done']
                        ]
                    ]
                ])
            ]);
        }
    }

    protected function handleUserMessage($chatId, $text)
    {
        if (strpos($text, '1️⃣') !== false || strpos($text, 'Kursga yozilish') !== false) {
            $name = User::where('telegram_id', $chatId)->value('name');
            $phone = User::where('telegram_id', $chatId)->value('phone');
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❗️<b>Malaka oshirish kursiga qo'shilish uchun oxirgi qadam:</b>\n\n" .
                    "📝 Ismingiz: <code>$name</code>\n" .
                    "📞 Telefon: <code>$phone</code>\n\n" .
                    "💳 <b>To'lov rekvizitlari:</b>\n" .
                    "🔹 UzCard: <code>6262 4700 5443 3169</code>\n" .
                    "🔹 Humo: <code>9860 3501 1851 8355</code>\n\n" .
                    "📋 <b>To'lovni amalga oshirish tartibi:</b>\n\n" .
                    "1️⃣ Yuqoridagi kartalardan biriga 200 000 (ikki yuz ming) so'm to'lovni Click, Payme, UzumBank, Zumrad kabi ilovalar orqali (kartadan-kartaga) yoki Paynet shaxobchalari orqali amalga oshiring ✅\n\n" .
                    "2️⃣ To'lov qilgandan so'ng <b>screenshot qilib mana shu botga yuboring</b> ✅\n" .
                    "   <i>(Screenshot'da summa, sana va vaqt ko'rinishi shart)</i>\n\n" .
                    "3️⃣ To'lovingizni 30 daqiqa ichida ko'rib chiqib, siz bilan bog'lanamiz ✅",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'remove_keyboard' => true
                ])
            ]);

            return response()->json(['ok' => true]);
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
        $this->sendMainMenu($chatId);
    }

    protected function sendMainMenu($chatId)
    {
        $keyboard = [
            ['1️⃣ Kursga yozilish'],
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

    public function setWebhook()
    {
        $url = url('/telegram/webhook');
        $response = $this->telegram->setWebhook(['url' => $url]);

        return response()->json([
            'success' => true,
            'result' => $response
        ]);
    }

    public function getWebhookInfo()
    {
        $response = $this->telegram->getWebhookInfo();
        return response()->json($response);
    }
    // Statistika
    protected function showStatistics($chatId)
    {
        $totalUsers = \App\Models\NotRegisteredUser::count();
        $registeredUsers = User::count();
        $todayRegistrations = User::whereDate('created_at', today())->count();

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "📊 <b>Bot Statistikasi</b>\n\n" .
                "👥 Jami foydalanuvchilar: <b>$totalUsers</b>\n" .
                "✅ Ro'yxatdan o'tganlar: <b>$registeredUsers</b>\n" .
                "🆕 Bugun ro'yxatdan o'tganlar: <b>$todayRegistrations</b>",
            'parse_mode' => 'HTML'
        ]);
    }

    // Reklama xabarini qabul qilish
    protected function handleBroadcastMessage($chatId, $message, $text, $photo, $document)
    {
        TelegramSession::clearSession($chatId);

        $hasPhoto = !empty($photo) && is_array($photo) && count($photo) > 0;
        $hasDocument = !empty($document) && is_array($document) && isset($document['file_id']);

        // Tasdiqlash xabari
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ Reklama qabul qilindi!\n\n" .
                "📊 Yuborilmoqda...",
            'parse_mode' => 'HTML'
        ]);

        // Broadcast yaratish
        $broadcast = \App\Models\Broadcast::create([
            'message' => $text ?? 'Media content',
            'total_users' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'completed' => false
        ]);

        // Barcha foydalanuvchilarni olish
        $allChatIds = collect();

        // Ro'yxatdan o'tgan userlar
        $registeredUsers = User::pluck('telegram_id');
        $allChatIds = $allChatIds->merge($registeredUsers);

        // Ro'yxatdan o'tmagan userlar (NotRegisteredUser modelingiz bor deb o'ylayman)
        try {
            $notRegisteredUsers = NotRegisteredUser::pluck('telegram_id');
            $allChatIds = $allChatIds->merge($notRegisteredUsers);
        } catch (\Exception $e) {
            \Log::info('NotRegisteredUser model not found');
        }

        // Unique qilish
        $allChatIds = $allChatIds->unique()->filter();

        $broadcast->update(['total_users' => $allChatIds->count()]);

        // Yuborish
        $sentCount = 0;
        $failedCount = 0;

        foreach ($allChatIds as $userChatId) {
            // Admin o'ziga yubormasin
            if ($userChatId == self::ADMIN_CHAT_ID) {
                continue;
            }

            // Avval yuborilganmi tekshirish
            $alreadySent = \App\Models\BroadcastLog::where('broadcast_id', $broadcast->id)
                ->where('telegram_id', $userChatId)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            try {
                // Rasm bilan yuborish
                if ($hasPhoto) {
                    $photoSizes = $photo instanceof \Illuminate\Support\Collection
                        ? $photo->toArray()
                        : (array) $photo;

                    $biggest = count($photoSizes) > 0 ? end($photoSizes) : null;

                    if (!$biggest || !isset($biggest['file_id'])) {
                        \Log::error('Telegram photo object invalid - no file_id', ['photo' => $photoSizes]);
                        return;
                    }

                    $fileId = $biggest['file_id'];

                    $this->telegram->sendPhoto([
                        'chat_id' => $userChatId,
                        'photo' => $fileId,
                        'caption' => $text
                    ]);
                }
                // Dokument bilan yuborish
                else if ($hasDocument) {
                    $fileId = $document->getFileId();

                    $this->telegram->sendDocument([
                        'chat_id' => $userChatId,
                        'document' => $fileId,
                        'caption' => $text
                    ]);
                }
                // Faqat text
                else if ($text) {
                    $this->telegram->sendMessage([
                        'chat_id' => $userChatId,
                        'text' => $text
                    ]);
                }

                // Log ga yozish
                \App\Models\BroadcastLog::create([
                    'broadcast_id' => $broadcast->id,
                    'telegram_id' => $userChatId,
                    'success' => true,
                    'sent_at' => now()
                ]);

                $sentCount++;

                // Telegram limit: 30 xabar/soniya
                usleep(35000); // 35ms kutish

            } catch (\Exception $e) {
                \Log::error('Broadcast failed for user', [
                    'user_chat_id' => $userChatId,
                    'error' => $e->getMessage()
                ]);

                \App\Models\BroadcastLog::create([
                    'broadcast_id' => $broadcast->id,
                    'telegram_id' => $userChatId,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'sent_at' => now()
                ]);

                $failedCount++;
            }
        }

        // Yakuniy natija
        $broadcast->update([
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'completed' => true
        ]);

        // Adminga natija
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ <b>Reklama yuborish yakunlandi!</b>\n\n" .
                "📊 Jami: <b>" . $broadcast->total_users . "</b>\n" .
                "✅ Muvaffaqiyatli: <b>$sentCount</b>\n" .
                "❌ Xatolik: <b>$failedCount</b>",
            'parse_mode' => 'HTML'
        ]);
    }
}
