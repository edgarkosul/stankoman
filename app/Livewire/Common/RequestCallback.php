<?php

namespace App\Livewire\Common;

use App\Models\CallbackRequest;
use App\Models\Product;
use App\Models\User;
use App\Rules\ValidPhone;
use App\Support\CallbackRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Кнопка и модалка «свяжитесь со мной».
 *
 * В отличие от OneClickOrder, это не одиночка в лэйауте, открываемая
 * глобальным событием: каждый встроенный экземпляр несёт свою кнопку.
 * Чат встраивает форму со своей темой и каналом, и при глобальном
 * `#[On]` вместе с ней открывалась бы форма на карточке товара под панелью.
 */
class RequestCallback extends Component
{
    /**
     * Эти две надписи называет вслух бот, поэтому они константы, а не строки
     * в разметке: у донора бот писал «впишите почту в форму под перепиской»,
     * когда на экране была только кнопка (13.09.2026), и уверял, что кнопки
     * заказа звонка на сайте нет (14.09.2026).
     */
    public const CALL_BUTTON = 'Заказать звонок менеджера';

    public const CONTACT_BUTTON = 'Оставить контакты менеджеру';

    public const CHANNEL_PHONE = 'phone';

    public const CHANNEL_EMAIL = 'email';

    #[Locked]
    public ?int $productId = null;

    /**
     * Тема из чата («счёт для организации») — ложится в комментарий, чтобы
     * менеджер понял, о чём речь, ещё до того как откроет переписку.
     */
    #[Locked]
    public ?string $topic = null;

    #[Locked]
    public string $source = CallbackRequest::SOURCE_SITE;

    /**
     * Каким контактом магазин ответит. Свойство вызова, а не настройка:
     * витрина обещает звонок и требует телефон, чат (решение заказчика
     * у донора 07.09.2026) просит почту. Второй контакт в обоих случаях
     * остаётся — необязательным полем.
     */
    #[Locked]
    public string $channel = self::CHANNEL_PHONE;

    public bool $isOpen = false;

    public bool $submitted = false;

    public string $customerName = '';

    public string $customerPhone = '';

    public string $customerEmail = '';

    public string $city = '';

    public string $callTime = '';

    public string $comments = '';

    public function mount(
        ?int $productId = null,
        ?string $topic = null,
        string $source = CallbackRequest::SOURCE_SITE,
        string $channel = self::CHANNEL_PHONE,
    ): void {
        $this->productId = $productId !== null && $productId > 0 ? $productId : null;
        $this->topic = filled($topic) ? Str::limit(trim($topic), 300, '') : null;
        $this->source = array_key_exists($source, CallbackRequest::sourceLabels()) ? $source : CallbackRequest::SOURCE_SITE;
        $this->channel = $channel === self::CHANNEL_EMAIL ? self::CHANNEL_EMAIL : self::CHANNEL_PHONE;

        $this->resetFormState();
    }

    public function open(): void
    {
        $this->resetErrorBag();
        $this->resetValidation();
        $this->resetFormState();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetErrorBag();
        $this->resetValidation();
        $this->resetFormState();
    }

    public function wantsEmail(): bool
    {
        return $this->channel === self::CHANNEL_EMAIL;
    }

    public function buttonLabel(): string
    {
        return $this->wantsEmail() ? self::CONTACT_BUTTON : self::CALL_BUTTON;
    }

    public function submit(CallbackRequestService $callbackRequests): void
    {
        $this->normalizePayload();

        $this->validate(
            $this->rules(),
            messages: $this->messages(),
            attributes: $this->validationAttributes(),
        );

        if (! $this->passesRateLimit()) {
            return;
        }

        $callbackRequest = $callbackRequests->submit(
            contact: [
                'name' => $this->customerName,
                'phone' => $this->customerPhone,
                'email' => $this->customerEmail,
                'city' => $this->city,
                'call_time' => $this->wantsEmail() ? null : $this->callTime,
                'comments' => $this->comments,
            ],
            context: [
                'source' => $this->source,
                'product_id' => $this->resolveProductId(),
                'user_id' => Auth::id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        );

        $this->submitted = true;

        /*
         * Для того, кто форму встроил: чат по этому событию связывает заявку
         * с диалогом. Сама форма про чат не знает, а событие в пустоту никому
         * не мешает.
         */
        $this->dispatch('callback-request-created', requestId: $callbackRequest->id);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'customerName' => ['required', 'string', 'min:2', 'max:100'],
            /*
             * Обязателен тот контакт, которым магазин ответит. Формат проверяется
             * у обоих: необязательное поле с опечаткой — не «пусто», а тихо
             * потерянный покупатель.
             */
            'customerPhone' => [$this->wantsEmail() ? 'nullable' : 'required', 'string', 'max:32', new ValidPhone],
            'customerEmail' => [$this->wantsEmail() ? 'required' : 'nullable', 'string', 'max:190', 'email:rfc'],
            'city' => ['nullable', 'string', 'max:100'],
            'callTime' => ['nullable', 'string', 'max:100'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'customerName.required' => 'Укажите имя.',
            'customerPhone.required' => 'Укажите телефон.',
            'customerEmail.required' => 'Укажите почту — на неё ответит менеджер.',
            'customerEmail.email' => 'Проверьте адрес почты: похоже, в нём опечатка.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'customerName' => 'имя',
            'customerPhone' => 'телефон',
            'customerEmail' => 'почта',
            'city' => 'город',
            'callTime' => 'время звонка',
            'comments' => 'комментарий',
        ];
    }

    public function render(): View
    {
        return view('livewire.common.request-callback', [
            'productName' => $this->productId !== null
                ? Product::query()->whereKey($this->productId)->value('name')
                : null,
        ]);
    }

    /**
     * Капчи на форме пока нет (фаза 7), поэтому лимит — единственная защита
     * почты менеджеров от флуда. Считаем по IP и по обязательному контакту:
     * по необязательному значило бы пропускать всех, кто его не заполнил.
     */
    private function passesRateLimit(): bool
    {
        $ip = (string) request()->ip();
        $contactField = $this->wantsEmail() ? 'customerEmail' : 'customerPhone';
        $contactHash = $this->wantsEmail()
            ? CallbackRequestService::emailHash($this->customerEmail)
            : CallbackRequestService::phoneHash($this->customerPhone);

        $ipKey = 'callback-request:ip:'.$ip;
        $contactKey = 'callback-request:contact:'.($contactHash ?? hash('sha256', $ip));

        if (RateLimiter::tooManyAttempts($ipKey, 8)) {
            $this->addError($contactField, 'Слишком много заявок с этого адреса. Попробуйте через '.RateLimiter::availableIn($ipKey).' сек.');

            return false;
        }

        if (RateLimiter::tooManyAttempts($contactKey, 1)) {
            $this->addError($contactField, 'Заявка с этим контактом уже отправлена. Повторить можно через '.RateLimiter::availableIn($contactKey).' сек.');

            return false;
        }

        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($contactKey, 60);

        return true;
    }

    private function resolveProductId(): ?int
    {
        if ($this->productId === null) {
            return null;
        }

        return Product::query()->whereKey($this->productId)->exists() ? $this->productId : null;
    }

    private function resetFormState(): void
    {
        $this->customerName = '';
        $this->customerPhone = '';
        $this->customerEmail = '';
        $this->city = '';
        $this->callTime = '';
        $this->comments = $this->topic ?? '';
        $this->submitted = false;

        $this->prefillContactFromAuthenticatedUser();
    }

    private function prefillContactFromAuthenticatedUser(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $this->customerName = (string) $user->name;
        $this->customerEmail = filled($user->email) ? (string) $user->email : '';
        $this->customerPhone = filled($user->phone) ? (string) $user->phone : '';
        $this->city = filled($user->shipping_city) ? (string) $user->shipping_city : '';
    }

    private function normalizePayload(): void
    {
        $this->customerName = $this->sanitize($this->customerName);
        $this->customerEmail = Str::lower(trim($this->customerEmail));
        $this->city = $this->sanitize($this->city);
        $this->callTime = $this->sanitize($this->callTime);
        $this->comments = trim($this->comments);

        $normalizedPhone = ValidPhone::normalize($this->customerPhone);

        if ($normalizedPhone !== null) {
            $this->customerPhone = $normalizedPhone;
        }
    }

    private function sanitize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
