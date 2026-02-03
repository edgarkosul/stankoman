<?php

namespace App\Livewire\Header;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Search extends Component
{
    public string $class = '';

    public function render(): View
    {
        return view('livewire.header.search');
    }
}
