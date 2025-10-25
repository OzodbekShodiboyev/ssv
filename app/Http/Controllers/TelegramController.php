<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use App\Models\User;
use App\Models\TelegramSession;

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
                        "<i>(agar doimiy ishlaydigan raqamingiz telegram raqamingiz bilan bir xil bo'lsa, pastdagi tugmani bosing</i>",
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
                        "1️⃣ To'lovni Click, Payme, UzumBank, Zumrad kabi ilovalar orqali (kartadan-kartaga) yoki Paynet shaxobchalari orqali amalga oshiring ✅\n\n" .
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
            'text' => "✅ To'lov cheki qabul qilindi!\n\n⏳ Chekingiz ko'rib chiqilmoqda...\n📞 Tez orada siz bilan bog'lanamiz (30 daqiqa ichida)",
        ]);

        // Adminga yuboriladigan umumiy caption
        $adminMessage = "🔔 <b>Yangi to'lov cheki!</b>\n\n" .
            "👤 Ism: <b>{$user->name}</b>\n" .
            "📞 Telefon: <code>{$user->phone}</code>\n" .
            "🆔 User ID: <code>{$user->telegram_id}</code>\n" .
            "📅 Vaqt: " . now()->format('d.m.Y H:i');

        if ($photo) {
            $photoSizes = $photo;
            $largestPhoto = end($photoSizes);
            $fileId = $largestPhoto->getFileId();

            $this->telegram->sendPhoto([
                'chat_id' => self::ADMIN_CHAT_ID,
                'photo' => $fileId,
                'caption' => $adminMessage,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Bog\'lanish', 'url' => "tg://user?id={$user->telegram_id}"]
                        ],
                        [
                            ['text' => '❌ Bog\'lanib bo\'lmadi', 'callback_data' => "cant_contact_{$user->telegram_id}"]
                        ]
                    ]
                ])
            ]);
        } elseif ($document) {
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
                        ],
                        [
                            ['text' => '❌ Bog\'lanib bo\'lmadi', 'callback_data' => "cant_contact_{$user->telegram_id}"]
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
}
