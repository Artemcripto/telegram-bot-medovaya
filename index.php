<?php
$token = getenv("TELEGRAM_BOT_TOKEN");
$data = json_decode(file_get_contents("php://input"), true);
file_put_contents("log.txt", date("Y-m-d H:i:s") . " | " . json_encode($data) . "\n", FILE_APPEND);

// Проверим, что это сообщение от пользователя
if (isset($data["message"])) {
    $chat_id = $data["message"]["chat"]["id"];
    $text = trim($data["message"]["text"]);

    if ($text === "/start") {
        sendMessage($chat_id, "👋 Добро пожаловать в гостиницу \"Медовая\"!\n\nЧем я могу помочь?");
    } else {
        sendMessage($chat_id, "Вы написали: $text");
    }
}

// Функция отправки сообщений
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
