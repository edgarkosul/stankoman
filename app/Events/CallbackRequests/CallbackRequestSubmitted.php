<?php

namespace App\Events\CallbackRequests;

use App\Models\CallbackRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallbackRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public CallbackRequest $callbackRequest) {}
}
