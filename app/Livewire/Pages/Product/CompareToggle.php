<?php

namespace App\Livewire\Pages\Product;

use App\Livewire\Header\CompareBadge;
use App\Support\CompareService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CompareToggle extends Component
{
    public int $productId;

    public bool $added = false;

    public string $variant = 'default';

    public bool $showLink = false;

    public ?string $tooltip = null;

    public function mount(int $productId, CompareService $compare): void
    {
        $this->productId = $productId;
        $this->syncState($compare);
    }

    public function toggle(CompareService $compare): void
    {
        $compare->toggle($this->productId);
        $this->syncState($compare);

        $ids = $compare->ids();

        $this->dispatch('compare:list-updated', ids: $ids);
        $this->dispatch('compare:updated', count: count($ids))
            ->to(CompareBadge::class);
    }

    #[On('compare:list-updated')]
    public function sync(CompareService $compare): void
    {
        $this->syncState($compare);
    }

    public function render(): View
    {
        return view('livewire.pages.product.compare-toggle');
    }

    protected function syncState(CompareService $compare): void
    {
        $this->added = $compare->contains($this->productId);
        $this->tooltip = $this->added ? 'Убрать из сравнения' : 'Добавить в сравнение';

        $this->showLink = ! in_array($this->variant, ['card', 'compare'], true)
            && $compare->isInCompare($this->productId);
    }
}
