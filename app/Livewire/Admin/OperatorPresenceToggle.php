<?php

namespace App\Livewire\Admin;

use App\Filament\Pages\AssistantSettings;
use App\Services\Ai\AssistantConfig;
use App\Services\Chat\OperatorPresence;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Переключатель «я на смене» в топбаре админки.
 *
 * Нужен потому, что расписание врёт в обе стороны: в субботу кто-то
 * разгребает заказы, а в среду в полдень весь отдел уехал к поставщику.
 * От присутствия зависит, предложит ли чат позвать менеджера или сразу
 * попросит контакты, — поэтому переключатель на глазах, а не в настройках.
 *
 * ЭТО НЕ ВЫКЛЮЧАТЕЛЬ БОТА, и путать их дорого. Здесь — есть ли сейчас
 * живой человек; отвечает ли в чате бот, решает «ИИ бот» → «Настройки
 * бота». Но подпись считается по ОБОИМ состояниям сразу: у донора она
 * считалась по одному присутствию и над выключенным ботом писала
 * «отвечает бот» — этот скриншот дошёл до заказчика.
 *
 * Ручное решение живёт не дольше своего TTL — и это главное в нём.
 * Переключатель без срока годности забывают включённым, и магазин
 * месяцами обещает живого менеджера в четыре утра.
 */
class OperatorPresenceToggle extends Component
{
    public function goOnline(OperatorPresence $presence): void
    {
        $presence->setOverride(true, Auth::id());
    }

    public function goOffline(OperatorPresence $presence): void
    {
        $presence->setOverride(false, Auth::id());
    }

    /** Вернуться к расписанию и автодетекту. */
    public function followSchedule(OperatorPresence $presence): void
    {
        $presence->clearOverride();
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
        $state = $presence->resolve();
        $online = $state['online'];
        $botEnabled = $assistant->enabled();

        [$label, $tone] = self::badge($botEnabled, $online);

        return view('livewire.admin.operator-presence-toggle', [
            'label' => $label,
            'tone' => $tone,
            'online' => $online,
            'source' => $state['source'],
            'until' => $state['until'],
            'schedule' => $presence->scheduleSummary(),
            /*
             * Что увидит покупатель, если откроет чат прямо сейчас, —
             * единственная формулировка, которую нельзя понять неправильно.
             * Она же объясняет связь двух решений: присутствие значит
             * разное при живом и при выключенном боте.
             */
            'chatEffect' => match (true) {
                ! $botEnabled => 'Поля для вопроса нет: покупатель видит форму контактов. Присутствие на это не влияет.',
                $online => 'Отвечает бот и предлагает позвать вас в чат.',
                default => 'Отвечает бот; звать человека он не предлагает — просит оставить почту.',
            },
            /*
             * Почему бот молчит — с именем виновника. Аварийный рубильник
             * из `.env` админ не найдёт никогда, а выключатель в «Настройках
             * бота» снимет сам. Без этой строки он щёлкает присутствием,
             * ничего не меняется, и объяснить это может только разработчик.
             */
            'botOffReason' => match (true) {
                $botEnabled => null,
                $assistant->disabledByAdmin() => 'Бот выключен в «Настройках бота».',
                default => 'Бот выключен разработчиком в конфигурации — из админки не включить.',
            },
            'settingsUrl' => $assistant->disabledByAdmin() ? AssistantSettings::getUrl(panel: 'admin') : null,
            'explanation' => match ($state['source']) {
                OperatorPresence::SOURCE_OVERRIDE => $online
                    ? 'Вы вручную включили режим «менеджер на смене».'
                    : 'Вы вручную ушли со смены.',
                OperatorPresence::SOURCE_ACTIVITY => 'Определено по работе в админке: раз вы здесь, значит на связи.',
                default => 'В админке сейчас никого, поэтому по расписанию: '.$presence->scheduleSummary().'.',
            },
        ]);
    }
}
