<?php

namespace App\Services\Chat\Data;

/**
 * Заявка, оставленная из чата и признанная своей.
 */
final readonly class ClaimedLead
{
    /**
     * @param  string  $contacts  что именно оставил покупатель: «почту», «почту и телефон».
     *                            По этому менеджер решает, чем отвечать
     */
    public function __construct(
        public int $id,
        public string $contacts,
    ) {}
}
