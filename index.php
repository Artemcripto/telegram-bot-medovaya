<?php
$token = getenv("TELEGRAM_BOT_TOKEN");
$data = json_decode(file_get_contents("php://input"), true);

// Логирование всех запросов
file_put_contents("log.txt", date("Y-m-d H:i:s") . " | " . json_encode($data) . "\n", FILE_APPEND);

// Если есть сообщение от пользователя
if (isset($data["message"])) {
    $chat_id = $data["message"]["chat"]["id"];
    $text = trim($data["message"]["text"]);

    // Ответ на команду /start
    if ($text === "/start") {
        sendMessage($chat_id, "👋 Добро пожаловать в гостиницу «Медовая» в Сочи!\n\nВыберите действие из меню ниже.");
        sendMainMenu($chat_id);
    } else {
        sendMessage($chat_id, "Вы написали: $text");
    }
}

// Функция отправки текстового сообщения
function sendMessage($chat_id, $text) {
    global $token;
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $payload = [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML"
    ];
    file_get_contents($url, false, stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]));
}

// Главное меню
function sendMainMenu($chat_id) {
    global $token;
    $keyboard = [
        "keyboard" => [
            [["text" => "📅 Забронировать"], ["text" => "🏷 Цены и номера"]],
            [["text" => "📞 Контакты"], ["text" => "ℹ️ О гостинице"]],
            [["text" => "❓ Задать вопрос"]],
        ],
        "resize_keyboard" => true,
        "one_time_keyboard" => false
    ];

    $url = "https://api.telegram.org/bot$token/sendMessage";
    $payload = [
        "chat_id" => $chat_id,
        "text" => "📋 Главное меню:",
        "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE)
    ];

    file_get_contents($url, false, stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json",
            "content" => json_encode($payload)
        ]
    ]));
}
