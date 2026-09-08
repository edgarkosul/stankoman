@php($noticeKey = \App\Support\AdminPanelLoginRedirect::statusKey())

@if (session('status') === $noticeKey)
    <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
        {{ __($noticeKey) }}
    </div>
@endif
