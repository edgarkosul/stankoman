<?php

namespace App\Jobs;

use App\Support\Products\ProductSearchSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Переиндексация пачки товаров после записи мимо событий модели.
 *
 * Держит id, а не модели: пачка приезжает из массового редактора или импорта,
 * где к моменту выполнения товар мог измениться ещё раз — актуальное состояние
 * читается из базы, а не из тела задачи.
 */
class SyncProductsToSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Повтор безопасен: индексация идемпотентна, документ переписывается целиком. */
    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $ids
     */
    public function __construct(public readonly array $ids) {}

    public function handle(ProductSearchSync $searchSync): void
    {
        $searchSync->syncIds($this->ids);
    }
}
