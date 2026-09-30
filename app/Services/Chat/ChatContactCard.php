<?php

namespace App\Services\Chat;

use App\Livewire\Common\RequestCallback;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Collection;

/**
 * Карточка «оставьте контакты» под перепиской: показывать ли, с какой
 * подводкой и с какой темой для заявки.
 *
 * Карточка ЛИПКАЯ: однажды предложенная, она держится, пока заявка не
 * оформлена. У донора решение сначала читало флаг с ПОСЛЕДНЕГО сообщения,
 * и на проде это означало вот что (диалог 17, 13.09.2026): покупатель
 * дописывал реплику, и форма пропадала вместе с темой. Бот говорил «я уже
 * передал ваш запрос менеджеру», а формы на экране не было, и покупатель
 * генератора за 96 450 ₽ так и не смог оставить почту.
 *
 * Граница липкости — окно ленты, которое грузит панель (последние 50
 * сообщений). В разговоре длиннее пометка может выпасть из окна, и карточка
 * погаснет. Осознанно: это очень долгий разговор, а колонка на диалоге
 * стоила бы миграции.
 */
final readonly class ChatContactCard
{
    private function __construct(
        public ?string $hint = null,
        public ?string $topic = null,
    ) {}

    /**
     * @param  Collection<int, ChatMessage>  $messages  лента, как её видит покупатель
     */
    public static function for(?ChatConversation $conversation, Collection $messages, bool $online): self
    {
        // Заявка уже оформлена — второй раз контакты не просим.
        if ($conversation?->callback_request_id !== null) {
            return new self;
        }

        $topic = self::topic($messages);

        /*
         * Покупатель написал контакт прямо в сообщении. Единственная ветка,
         * не зависящая от того, что решила модель: у донора она прочла
         * спрятанную от неё почту как вопрос «а какая у вас почта» и назвала
         * адрес магазина. Флаг ставит сервер, когда записывает реплику.
         */
        if ($messages->contains(static fn (ChatMessage $message): bool => $message->isFromVisitor()
            && (bool) ($message->meta['contact_in_chat'] ?? false))) {
            // Кнопку называем по имени: формы на экране не видно, пока
            // её не нажали, и «впишите в форму» искать было бы негде.
            return new self(
                'Похоже, вы написали контакт в сообщении. Нажмите «'.RequestCallback::CONTACT_BUTTON.'» '
                    .'ниже и впишите его — так обращение дойдёт до менеджера, и он ответит письмом.',
                $topic,
            );
        }

        /*
         * Предложение прозвучало ХОТЬ РАЗ: бот вызвал request_contact или
         * менеджер попросил контакты. С эскалацией это не путать: бот сам
         * предлагает разговор (счёт, подбор, расчёт доставки), а вопрос
         * человеку при этом не передан.
         */
        if ($messages->contains(static fn (ChatMessage $message): bool => (bool) ($message->meta['callback_requested'] ?? false))) {
            return new self('Оставьте почту — менеджер ответит письмом и поможет.', $topic);
        }

        /*
         * Разговор ведёт человек — карточку сама по себе эскалация больше
         * не показывает. Постоянная «оставьте почту» под живой перепиской
         * с менеджером это шум: покупателю уже отвечают.
         */
        if ($conversation !== null && ! $conversation->isBotLed()) {
            return new self;
        }

        if (! (bool) $conversation?->isEscalated()) {
            return new self;
        }

        // Ночью «ответим» без оговорки читается как «через пять минут».
        return new self(
            $online
                ? 'Вопрос передан менеджеру. Оставьте почту — ответим письмом.'
                : 'Вопрос передан менеджеру. Сейчас нерабочее время — оставьте почту, '
                    .'и вам ответят, как только магазин откроется.',
            $topic,
        );
    }

    /**
     * Тема, которую бот назвал, предлагая форму. Подставляется в комментарий
     * заявки: менеджер отвечает, уже понимая вопрос.
     *
     * Берётся с ПОСЛЕДНЕГО сообщения, где она есть, а не с последнего вообще:
     * иначе покупатель, дописавший реплику, оформлял заявку без темы.
     *
     * @param  Collection<int, ChatMessage>  $messages
     */
    private static function topic(Collection $messages): ?string
    {
        $topic = $messages->reverse()
            ->map(static fn (ChatMessage $message): mixed => $message->meta['callback_topic'] ?? null)
            ->first(static fn (mixed $value): bool => filled($value));

        return filled($topic) ? 'Вопрос из чата: '.$topic : null;
    }
}
