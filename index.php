<?php
$token = getenv("TELEGRAM_BOT_TOKEN");
$openai_key = getenv("OPENAI_API_KEY");
$admin_chat_id = "672463437";

$data = json_decode(file_get_contents("php://input"), true);
$chat_id = $data["message"]["chat"]["id"] ?? null;
$text = trim($data["message"]["text"] ?? "");
$state_file = "states/$chat_id.json";
$session_file = "sessions/$chat_id.json";

if (!file_exists("states")) mkdir("states", 0777, true);
if (!file_exists("sessions")) mkdir("sessions", 0777, true);
$state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : ["step" => "menu"];

file_put_contents("log.txt", date("Y-m-d H:i:s") . " | $chat_id | $text\n", FILE_APPEND);

// === Обработка callback-кнопок ===
if (!empty($data["callback_query"])) {
    $cid = $data["callback_query"]["from"]["id"];
    $callback_data = $data["callback_query"]["data"];

    // Ответить пользователю
    if (strpos($callback_data, "reply_to_") === 0 && $cid == $admin_chat_id) {
        $target_id = str_replace("reply_to_", "", $callback_data);
        file_put_contents("last_user.txt", $target_id);
        sendMessage($cid, "✍ Введите ответ для пользователя $target_id:");
        exit;
    }

    // Закрыть вопрос
    if (strpos($callback_data, "close_") === 0 && $cid == $admin_chat_id) {
        $target_id = str_replace("close_", "", $callback_data);
        sendMessage($target_id, "✅ Ваш вопрос обработан администратором.");
        sendMessage($cid, "✅ Вопрос закрыт.");
        exit;
    }

    exit;
}

// === Ответ администратора вручную ===
if ($chat_id === $admin_chat_id && file_exists("last_user.txt")) {
    $target = trim(file_get_contents("last_user.txt"));
    sendMessage($target, "📩 Ответ от администратора:\n\n" . $text);
    sendMessage($chat_id, "✅ Ответ отправлен пользователю.");
    unlink("last_user.txt");
    exit;
}

// === Команды пользователя ===
if ($text === "/start") {
    $state["step"] = "menu";
    file_put_contents($session_file, json_encode([]));
    sendMessage($chat_id, "👋 Добро пожаловать в *гостиницу Медовая* в Сочи!\n\nЗдесь вы можете забронировать номер, посмотреть цены или задать вопрос. Выберите вариант из меню ниже 👇", "Markdown");
    sendMenu($chat_id);
    file_put_contents($state_file, json_encode($state));
    exit;
}

if ($text === "/reset") {
    file_put_contents($session_file, json_encode([]));
    sendMessage($chat_id, "💬 История диалога сброшена.");
    sendMenu($chat_id);
    exit;
}

if ($text === "/help") {
    sendMessage($chat_id, "ℹ️ Напишите:\n\n📅 *Забронировать* – чтобы перейти к бронированию\n🏷 *Цены и номера* – чтобы узнать стоимость\n❓ *Задать вопрос* – задать любой вопрос\n\nИли нажмите на кнопки ниже.", "Markdown");
    exit;
}

if (in_array(mb_strtolower($text), ["привет", "здравствуйте", "добрый день", "добрый вечер"])) {
    sendMessage($chat_id, "Привет! Чем могу помочь? Выберите вариант из меню или задайте вопрос.");
    sendMenu($chat_id);
    exit;
}

if ($state["step"] === "ask") {
    $state["step"] = "menu";
    file_put_contents($state_file, json_encode($state));
    $answer = getChatGPTAnswerWithContext($text, $openai_key, $chat_id);
    sendMessage($chat_id, "🤖 Ответ:\n" . $answer);
    sendMenu($chat_id);
    exit;
}

// === Обработка кнопок меню ===
switch (mb_strtolower($text)) {
    case "📅 забронировать":
        sendInlineButtons($chat_id, "📅 Бронирование доступно на сайте:", [[["text" => "Перейти к бронированию", "url" => "https://booking-medovaya.agast.ru"]]]);
        break;
    case "🏷 цены и номера":
        sendPhoto($chat_id, "https://hotel-medovaya.ru/wp-content/uploads/2025/05/room1.jpg", "🌅 Номер с видом на море\nЦена: от 4500₽/сутки\nПодробнее: https://hotel-medovaya.ru/gostinichnye-nomera-v-adlere/");
        break;
    case "📞 контакты":
        sendMessage($chat_id, "📍 Контакты:\n📞 *+7 (938) 494-41-41*\n✉️ info@hotel-medovaya.ru\n🌐 [Перейти на сайт](https://hotel-medovaya.ru/contacts/)", "Markdown");
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

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===

function sendMessage($chat_id, $text, $parse_mode = null) {
    global $token;
    $payload = ["chat_id" => $chat_id, "text" => $text];
    if ($parse_mode) $payload["parse_mode"] = $parse_mode;
    $options = ["http" => ["method"  => "POST", "header"  => "Content-Type:application/json", "content" => json_encode($payload, JSON_UNESCAPED_UNICODE)]];
    file_get_contents("https://api.telegram.org/bot$token/sendMessage", false, stream_context_create($options));
}

function sendMenu($chat_id) {
    global $token;
    $keyboard = [
        "keyboard" => [
            [["text" => "📅 Забронировать"], ["text" => "🏷 Цены и номера"]],
            [["text" => "📞 Контакты"], ["text" => "ℹ️ О гостинице"]],
            [["text" => "❓ Задать вопрос"]]
        ],
        "resize_keyboard" => true,
        "one_time_keyboard" => false
    ];
    $payload = ["chat_id" => $chat_id, "text" => "📋 Главное меню:", "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE)];
    $options = ["http" => ["method"  => "POST", "header"  => "Content-Type:application/json", "content" => json_encode($payload)]];
    file_get_contents("https://api.telegram.org/bot$token/sendMessage", false, stream_context_create($options));
}

function sendPhoto($chat_id, $photo_url, $caption = "") {
    global $token;
    $payload = ["chat_id" => $chat_id, "photo" => $photo_url, "caption" => $caption];
    $options = ["http" => ["method"  => "POST", "header"  => "Content-Type:application/json", "content" => json_encode($payload, JSON_UNESCAPED_UNICODE)]];
    file_get_contents("https://api.telegram.org/bot$token/sendPhoto", false, stream_context_create($options));
}

function sendInlineButtons($chat_id, $text, $buttons) {
    global $token;
    $keyboard = ["inline_keyboard" => $buttons];
    $payload = ["chat_id" => $chat_id, "text" => $text, "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE)];
    $options = ["http" => ["method"  => "POST", "header"  => "Content-Type:application/json", "content" => json_encode($payload)]];
    file_get_contents("https://api.telegram.org/bot$token/sendMessage", false, stream_context_create($options));
}

function getChatGPTAnswerWithContext($user_input, $apiKey, $chat_id) {
    global $session_file, $admin_chat_id;

    $context = file_exists($session_file) ? json_decode(file_get_contents($session_file), true) : [];
    $context[] = ["role" => "user", "content" => $user_input];

    $messages = array_merge([
        ["role" => "system", "content" => "Ты — вежливый и дружелюбный помощник гостиницы \"Медовая\" в Сочи. Отвечай кратко и по делу. Если не уверен — передай вопрос администратору."]
    ], $context);

    $data = ["model" => "gpt-3.5-turbo", "messages" => $messages, "temperature" => 0.7];
    $options = ["http" => ["method" => "POST", "header" => "Content-Type: application/json\r\nAuthorization: Bearer $apiKey", "content" => json_encode($data)]];

    $response = file_get_contents("https://api.openai.com/v1/chat/completions", false, stream_context_create($options));
    $result = json_decode($response, true);
    $reply = $result["choices"][0]["message"]["content"] ?? "";

    // логируем
    file_put_contents("log.txt", "GPT: " . $reply . "\n", FILE_APPEND);

    if (mb_strlen(trim($reply)) < 10 || stripos($reply, "не знаю") !== false || stripos($reply, "не могу помочь") !== false) {
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "✍ Ответить", "callback_data" => "reply_to_$chat_id"],
                    ["text" => "✅ Закрыть", "callback_data" => "close_$chat_id"]
                ]
            ]
        ];
        sendInlineButtons($admin_chat_id, "❗️ Новый вопрос от пользователя:\n\n\"$user_input\"\n\n🆔 $chat_id", $keyboard);
        return "⏳ Я передал ваш вопрос администратору. Он скоро свяжется с вами.";
    }

    $context[] = ["role" => "assistant", "content" => $reply];
    file_put_contents($session_file, json_encode(array_slice($context, -10)));
    return $reply;
}
