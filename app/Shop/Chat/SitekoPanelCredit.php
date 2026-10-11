<?php

namespace App\Shop\Chat;

use Siteko\Chatbot\Chat\Contracts\PanelCredit;
use Siteko\Chatbot\Chat\Credit;

/**
 * «Сделано в Siteko» под полем ввода — всегда, как было в панели
 * intertooler до пакета (решение Эдгара 10.10, реестр bots 10.3).
 */
final class SitekoPanelCredit implements PanelCredit
{
    public function credit(): ?Credit
    {
        return new Credit('Сделано в', 'Siteko', 'https://www.siteko.net/development');
    }
}
