<?php

namespace App\Livewire\Pages\Orders;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $payment = '';

    #[Url(except: '')]
    public string $period = '';

    #[Url(except: 'date_desc')]
    public string $sort = 'date_desc';

    public function updated(string $name): void
    {
        $this->resetPage();
    }

    #[Title('Мои заказы')]
    public function render(): View
    {
        $baseQuery = Order::query()->where('user_id', Auth::id());

        $availableStatuses = (clone $baseQuery)
            ->whereNotNull('status')
            ->toBase()
            ->select('status')
            ->distinct()
            ->pluck('status')
            ->values()
            ->all();

        $availablePaymentStatuses = (clone $baseQuery)
            ->whereNotNull('payment_status')
            ->toBase()
            ->select('payment_status')
            ->distinct()
            ->pluck('payment_status')
            ->values()
            ->all();

        $query = clone $baseQuery;

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        if ($this->payment !== '') {
            $query->where('payment_status', $this->payment);
        }

        if ($this->period !== '' && ctype_digit($this->period)) {
            $query->where('created_at', '>=', now()->subDays((int) $this->period));
        }

        if ($this->search !== '') {
            $term = trim($this->search);

            $query->where(function ($subQuery) use ($term): void {
                $subQuery->where('order_number', 'like', '%'.$term.'%');

                if (ctype_digit($term)) {
                    $subQuery->orWhere('id', (int) $term);
                }
            });
        }

        $query = match ($this->sort) {
            'date_asc' => $query->orderBy('created_at'),
            'total_desc' => $query->orderByDesc('grand_total')->orderByDesc('created_at'),
            'total_asc' => $query->orderBy('grand_total')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $orders = $query->paginate(10);

        return view('livewire.pages.orders.index', [
            'orders' => $orders,
            'availableStatuses' => $availableStatuses,
            'availablePaymentStatuses' => $availablePaymentStatuses,
        ])->layout('layouts.catalog', ['title' => 'Мои заказы']);
    }
}
