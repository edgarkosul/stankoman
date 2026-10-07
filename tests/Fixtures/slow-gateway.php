<?php

/*
 * Медленный /embeddings для AitunnelHedgeTest — роутер `php -S`.
 *
 * Поведение задаёт текст запроса, `<режим>:<метка>`: дубль приходит с тем же
 * текстом, и различить запросы можно только по порядку. Счётчик — файл
 * на метку в папке SLOW_GATEWAY_DIR.
 *
 *   fast       — отвечают все сразу;
 *   hang-first — первый висит 3 с, остальные отвечают сразу;
 *   hang-all   — висят все, по 5 с.
 *
 * Первое число вектора — номер запроса, ответившего на этот: 0.1, 0.2…
 */

$body = json_decode((string) file_get_contents('php://input'), true);
[$mode, $label] = array_pad(explode(':', (string) ($body['input'][0] ?? ''), 2), 2, '');

$counter = fopen(getenv('SLOW_GATEWAY_DIR').'/'.md5($label), 'c+');
flock($counter, LOCK_EX);
$number = (int) stream_get_contents($counter) + 1;
ftruncate($counter, 0);
rewind($counter);
fwrite($counter, (string) $number);
flock($counter, LOCK_UN);
fclose($counter);

if ($mode === 'hang-all') {
    sleep(5);
} elseif ($mode === 'hang-first' && $number === 1) {
    sleep(3);
}

header('Content-Type: application/json');

echo json_encode([
    'data' => [['index' => 0, 'embedding' => [$number / 10, 0.2, 0.3, 0.4]]],
    'usage' => ['prompt_tokens' => 3],
]);
