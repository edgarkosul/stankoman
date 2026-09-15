<?php

namespace App\Services\Ai\Exceptions;

/**
 * Шлюз завернул запрос из-за персональных данных (`HTTP 400 · pii_blocked`).
 *
 * Сейчас на ключе стоит режим маскирования, и этого не случается. Но режим
 * переключается в панели, вне нашего кода, — и если кто-то поставит блокировку,
 * невинное «перезвоните на 8 921…» начнёт ронять ответы. Отдельный тип нужен,
 * чтобы отличить это от сетевого сбоя: посетителю тут надо сказать, что мы
 * не пересылаем контакты боту, а не «сервис недоступен».
 */
final class PiiBlockedException extends LlmException
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(string $message, public readonly array $types = [])
    {
        parent::__construct($message);
    }
}
