<?php

namespace App\Livewire\Pages\Orders;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Show extends Component
{
    public Order $order;

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function mount(string $date, string $seq): void
    {
        try {
            $orderDate = Carbon::createFromFormat('d-m-y', $date)->toDateString();
        } catch (\Throwable $exception) {
            throw new NotFoundHttpException;
        }

        $this->order = Order::query()
            ->with(['items.product'])
            ->whereDate('order_date', $orderDate)
            ->where('seq', (int) $seq)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    #[Title('Заказ')]
    public function render(): View
    {
        return view('livewire.pages.orders.show', [
            'order' => $this->order,
        ])->layout('layouts.catalog', [
            'title' => 'Заказ '.$this->order->order_number,
        ]);
    }
}
