<?php

namespace App\Services\Messengers;

use RuntimeException;

/**
 * Чата для бота больше нет: его остановили или удалили из чата.
 * Повтор тут не поможет — канал надо выключить и показать почему.
 */
final class ChatGone extends RuntimeException {}
