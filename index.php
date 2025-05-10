<?php
$token = getenv("TELEGRAM_BOT_TOKEN");
$openai_key = getenv("OPENAI_API_KEY");
$admin_chat_id = "672463437"; // обязательно строкой!

$data = json_decode(file_get_contents("php://input"), true);
$chat_id = $data["message"]["chat"]["id"] ?? null;
$text = trim($data["message"]["text"] ?? "");
$state_file = "states/$chat_id.json";
$session_file = "sessions/$chat_id.json";

if (!file_exists("states")) mkdir("states", 0777, true);
if (!file_exists("sessions")) mkdir("sessions", 0777, true);
$state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : ["step" => "menu"];

file_put_contents("log.txt", date("Y-m-d H:i:s") . " | $chat_id | $text
", FILE_APPEND);

if (!empty($data["callback_query"])) {
    $cid = $data["callback_query"]["from"]["id"];
    $callback_data = $data["callback_query"]["data"];
    if (strpos($callback_data, "reply_to_") === 0 && $cid == $admin_chat_id) {
        $target_id = str_replace("reply_to_", "", $callback_data);
        file_put_contents("last_user.txt", $target_id);
        sendMessage($cid, "✍ Введите ответ для пользователя $target_id:");
    }
    exit;
}

if ($chat_id === $admin_chat_id && file_exists("last_user.txt")) {
    $target = trim(file_get_contents("last_user.txt"));
    sendMessage($target, "📩 Ответ от администратора:

" . $text);
    sendMessage($chat_id, "✅ Ответ отправлен пользователю.");
    unlink("last_user.txt");
    exit;
}

if ($text === "/start") {
    $state["step"] = "menu";
    file_put_contents($session_file, json_encode([]));
    sendMessage($chat_id, "👋 Добро пожаловать в *гостиницу Медовая* в Сочи!

Здесь вы можете забронировать номер, посмотреть цены или задать вопрос. Выберите вариант из меню ниже 👇", "Markdown");
    sendMenu($chat_id);
    file_put_contents($state_file, json_encode($state));
    exit;
}

if ($state["step"] === "ask") {
    $state["step"] = "menu";
    file_put_contents($state_file, json_encode($state));
    $answer = getChatGPTAnswerWithContext($text, $openai_key, $chat_id);
    sendMessage($chat_id, "🤖 Ответ:
" . $answer);
    sendMenu($chat_id);
    exit;
}

switch (mb_strtolower($text)) {
    case "📅 забронировать":
        sendInlineButtons($chat_id, "📅 Бронирование доступно на сайте:", [[["text" => "Перейти к бронированию", "url" => "https://booking-medovaya.agast.ru"]]]);
        break;
    case "🏷 цены и номера":
        sendPhoto($chat_id, "https://hotel-medovaya.ru/wp-content/uploads/2025/05/room1.jpg", "🌅 Номер с видом на море
Цена: от 4500₽/сутки");
        break;
    case "📞 контакты":
        sendMessage($chat_id, "📍 Контакты:
📞 *+7 (938) 494-41-41*
✉️ info@hotel-medovaya.ru
🌐 [Перейти на сайт](https://hotel-medovaya.ru/contacts/)", "Markdown");
        break;
    case "ℹ️ о гостинице":
        sendMessage($chat_id, "🏨 Подробнее о гостинице: [Смотреть](https://hotel-medovaya.ru/gostinitsa-v-adlere-2-2/)", "Markdown");
        break;
    case "❓ задать вопрос":
        $state["step"] = "ask";
        file_put_contents($state_file, json_encode($state));
        sendMessage($chat_id, "Введите ваш вопрос, и я постараюсь ответить:");
        break;
    default:
        sendMessage($chat_id, "Пожалуйста, выберите вариант из меню ниже:");
        sendMenu($chat_id);
        break;
}

file_put_contents($state_file, json_encode($state));

function sendMessage($chat_id, $text, $parse_mode = null) {
    global $token;
    $payload = ["chat_id" => $chat_id, "text" => $text];
    if ($parse_mode) $payload["parse_mode"] = $parse_mode;
    sendRequest("sendMessage", $payload);
}

function sendMenu($chat_id) {
    $keyboard = [
        "keyboard" => [
            [["text" => "📅 Забронировать"], ["text" => "🏷 Цены и номера"]],
            [["text" => "📞 Контакты"], ["text" => "ℹ️ О гостинице"]],
            [["text" => "❓ Задать вопрос"]]
        ],
        "resize_keyboard" => true
    ];
    sendRequest("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "📋 Главное меню:",
        "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE)
    ]);
}

function sendPhoto($chat_id, $photo_url, $caption = "") {
    sendRequest("sendPhoto", [
        "chat_id" => $chat_id,
        "photo" => $photo_url,
        "caption" => $caption
    ]);
}

function sendInlineButtons($chat_id, $text, $buttons) {
    $markup = ["inline_keyboard" => $buttons];
    sendRequest("sendMessage", [
        "chat_id" => $chat_id,
        "text" => $text,
        "reply_markup" => json_encode($markup, JSON_UNESCAPED_UNICODE)
    ]);
}

function sendRequest($method, $data) {
    global $token;
    $url = "https://api.telegram.org/bot$token/$method";
    $options = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json",
            "content" => json_encode($data, JSON_UNESCAPED_UNICODE)
        ]
    ];
    file_get_contents($url, false, stream_context_create($options));
}

function getChatGPTAnswerWithContext($user_input, $apiKey, $chat_id) {
    global $session_file, $admin_chat_id;
    $context = file_exists($session_file) ? json_decode(file_get_contents($session_file), true) : [];
    $context[] = ["role" => "user", "content" => $user_input];
    $messages = array_merge([
        ["role" => "system", "content" => "Ты — вежливый и дружелюбный помощник гостиницы Медовая в Сочи."]
    ], $context);

    $data = ["model" => "gpt-3.5-turbo", "messages" => $messages, "temperature" => 0.7];
    $options = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json
Authorization: Bearer $apiKey",
            "content" => json_encode($data)
        ]
    ];
    $response = file_get_contents("https://api.openai.com/v1/chat/completions", false, stream_context_create($options));
    $result = json_decode($response, true);
    $reply = $result["choices"][0]["message"]["content"] ?? "";

    if (mb_strlen(trim($reply)) < 10) {
        sendInlineButtons($admin_chat_id, "❗️ Новый вопрос от пользователя:

"$user_input"

🆔 $chat_id", [
            [[ "text" => "✍ Ответить", "callback_data" => "reply_to_$chat_id" ]]
        ]);
        return "⏳ Я передал ваш вопрос администратору. Он скоро свяжется с вами.";
    }

    $context[] = ["role" => "assistant", "content" => $reply];
    file_put_contents($session_file, json_encode(array_slice($context, -10)));
    return $reply;
}
