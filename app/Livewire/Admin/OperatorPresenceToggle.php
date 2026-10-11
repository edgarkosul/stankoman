<?php

namespace App\Livewire\Admin;

use App\Filament\Pages\AssistantSettings;
use Illuminate\View\View;
use Livewire\Component;
use Siteko\Chatbot\Ai\AssistantConfig;
use Siteko\Chatbot\Chat\OperatorPresence;

/**
 * Режим чата в топбаре админки: звать ли менеджера в чат.
 *
 * Нужен потому, что расписание врёт в обе стороны: в субботу кто-то
 * разгребает заказы, а в среду в полдень весь отдел уехал к поставщику.
 * От режима зависит, предложит ли чат позвать менеджера или сразу
 * попросит контакты, — поэтому переключатель на глазах, а не в настройках.
 *
 * Режимы — пакета бота (решение Эдгара 10.10, реестр bots 10.3): «не звать»,
 * «по расписанию», «всегда». Срока годности у них нет, и автоматического
 * «раз вы в админке — вы на связи» больше нет: выбор действует, пока его
 * не поменяют, а «по расписанию» — то, к чему возвращаются.
 *
 * ЭТО НЕ ВЫКЛЮЧАТЕЛЬ БОТА, и путать их дорого. Здесь — есть ли сейчас
 * живой человек; отвечает ли в чате бот, решает «ИИ бот» → «Настройки
 * бота». Но подпись считается по ОБОИМ состояниям сразу: у донора она
 * считалась по одному присутствию и над выключенным ботом писала
 * «отвечает бот» — этот скриншот дошёл до заказчика.
 */
class OperatorPresenceToggle extends Component
{
    public function setMode(string $mode, OperatorPresence $presence): void
    {
        $presence->setMode($mode);
    }

    /**
     * Подпись и цвет — по КОМБИНАЦИИ «работает ли бот» и «есть ли человек».
     *
     * В подписи не «что включено», а что произойдёт, если покупатель
     * откроет чат прямо сейчас. Цвет по той же мерке: зелёный — ответят
     * в чате, жёлтый — ответа в чате не будет, но обращение примем.
     *
     * Вынесено статикой, чтобы проверяться тестом: подпись — ровно то
     * место, где рассогласование двух переключателей становится видно.
     *
     * @return array{0: string, 1: string} подпись и тон (live|contacts)
     */
    public static function badge(bool $botEnabled, bool $online): array
    {
        return match (true) {
            ! $botEnabled => ['Чат: только форма контактов', 'contacts'],
            $online => ['Чат: отвечает бот, зовёт менеджера', 'live'],
            default => ['Чат: отвечает бот, просит контакты', 'live'],
        };
    }

    public function render(OperatorPresence $presence, AssistantConfig $assistant): View
    {
        $online = $presence->isOnline();
        $botEnabled = $assistant->enabled();
        $schedule = $presence->scheduleSummary();

        [$label, $tone] = self::badge($botEnabled, $online);

        return view('livewire.admin.operator-presence-toggle', [
            'label' => $label,
            'tone' => $tone,
            'mode' => $presence->mode(),
            'modes' => [
                OperatorPresence::MODE_ON => 'Звать всегда',
                OperatorPresence::MODE_SCHEDULE => 'По расписанию',
                OperatorPresence::MODE_OFF => 'Не звать в чат',
            ],
            /*
             * Что увидит покупатель, если откроет чат прямо сейчас, —
             * единственная формулировка, которую нельзя понять неправильно.
             * Она же объясняет связь двух решений: присутствие значит
             * разное при живом и при выключенном боте.
             */
            'chatEffect' => match (true) {
                ! $botEnabled => 'Поля для вопроса нет: покупатель видит форму контактов. Режим на это не влияет.',
                $online => 'Отвечает бот и предлагает позвать вас в чат.',
                default => 'Отвечает бот; звать человека он не предлагает — просит оставить почту.',
            },
            /*
             * Почему бот молчит — с именем виновника. Аварийный рубильник
             * из `.env` админ не найдёт никогда, а выключатель в «Настройках
             * бота» снимет сам. Без этой строки он щёлкает режимом,
             * ничего не меняется, и объяснить это может только разработчик.
             */
            'botOffReason' => match (true) {
                $botEnabled => null,
                $assistant->disabledByAdmin() => 'Бот выключен в «Настройках бота».',
                default => 'Бот выключен разработчиком в конфигурации — из админки не включить.',
            },
            'settingsUrl' => $assistant->disabledByAdmin() ? AssistantSettings::getUrl(panel: 'admin') : null,
            'explanation' => match ($presence->mode()) {
                OperatorPresence::MODE_ON => 'Менеджера зовут в чат в любое время.',
                OperatorPresence::MODE_OFF => 'Менеджера в чат не зовут: когда нужен человек, бот просит оставить контакты.',
                default => ($online ? 'Сейчас рабочее время' : 'Сейчас нерабочее время').' — по режиму работы: '.$schedule.'.',
            },
            'hold' => (int) config('chatbot.operators.hold_minutes'),
        ]);
    }
}
