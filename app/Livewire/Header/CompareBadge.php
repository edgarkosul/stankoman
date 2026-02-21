<?php

namespace App\Livewire\Header;

use App\Support\CompareService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CompareBadge extends Component
{
    public int $count = 0;

    public function mount(CompareService $compare): void
    {
        $this->count = $compare->count();
    }

    public function goToComparePage(): void
    {
        if ($this->count > 0) {
            $this->redirectRoute('compare.index');
        }
    }

    #[On('compare:updated')]
    public function refresh(?int $count = null): void
    {
        $this->count = $count ?? app(CompareService::class)->count();
    }

    public function render(): View
    {
        return view('livewire.header.compare-badge');
    }
}
