<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал доступности шлюза — по КАЖДОМУ адресу отдельно.
 *
 * Отдельная таблица, а не строчка в логе, по двум причинам.
 *
 * Первая: доступность надо считать, а не читать глазами. «Стало ли хуже
 * за последнюю неделю» — это запрос, и он должен быть однострочным.
 *
 * Вторая, и главная: разрез по адресу. Авария на проде kratonshop 17.09.2026 выглядела как
 * «aitunnel часто недоступен», а на деле шлюз отдавал два адреса
 * Cloudflare, один из которых не принимал SYN с этого сервера, а второй
 * отвечал за 20 мс. Проба по ИМЕНИ ХОСТА показала бы ровный флап на 50%
 * и увела бы разбираться в поддержку сервиса — на неделю. Проба по
 * адресам называет виновного сразу.
 *
 * Чего здесь нет: тел запросов и ответов. Это измерение связности,
 * а не трафика; проба ходит на бесключевой каталог моделей и никаких
 * данных магазина не несёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_gateway_probes', function (Blueprint $table): void {
            $table->id();

            // IPv6 тоже помещается: 45 символов — максимум для записи
            // с встроенным IPv4.
            $table->string('ip', 45)->index();

            $table->boolean('ok')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);

            // Короткая причина: «connect timeout», «tls», «http 502».
            // Полный текст ошибки не нужен — он в логе.
            $table->string('error', 120)->nullable();

            // Пин выбран этим прогоном: видно, когда и почему переехали.
            $table->boolean('pinned')->default(false);

            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_gateway_probes');
    }
};
