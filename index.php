<?php file_put_contents("log.txt", date("Y-m-d H:i:s") . " | " . file_get_contents("php://input") . "\n", FILE_APPEND);

echo 'Бот работает';
