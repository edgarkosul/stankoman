@php
    $c = fn($key, $default = '') => config("company.$key", $default);
    $bank = config('company.bank', []);
    $coverSrc = $cover ?? null;
@endphp

<style>
    @page {
        margin: 8mm 18mm 16mm;
    }

    /* статические TTF (не variable) */
    @font-face {
        font-family: "Inter";
        src: url("{{ storage_path('fonts/RobotoCondensed-Regular.ttf') }}") format("truetype");
        font-weight: 400;
        font-style: normal;
    }

    @font-face {
        font-family: "Inter";
        src: url("{{ storage_path('fonts/RobotoCondensed-Bold.ttf') }}") format("truetype");
        font-weight: 700;
        font-style: normal;
    }

    @font-face {
        font-family: "Inter";
        src: url("{{ storage_path('fonts/RobotoCondensed-Italic.ttf') }}") format("truetype");
        font-weight: 400;
        font-style: italic;
    }

    @font-face {
        font-family: "Inter";
        src: url("{{ storage_path('fonts/RobotoCondensed-BoldItalic.ttf') }}") format("truetype");
        font-weight: 700;
        font-style: italic;
    }

    body {
        font-family: Inter, sans-serif;
        color: #111;
        font-size: 12px;
        line-height: 1.45;
    }

    h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 12px 0;
        text-align: center;

    }

    h2 {
        font-size: 14px;
        margin: 12px 0 8px;
    }

    .muted {
        color: #666;
    }

    /* Лэйаут-таблица (2 колонки) */
    table.layout {
        width: 100%;
        border-collapse: collapse;
    }

    table.layout td {
        vertical-align: top;
    }

    td.col-left {
        width: 45%;
        padding-right: 12px;
    }

    td.col-right {
        width: 55%;
        padding-left: 12px;
    }

    /* Карточка цены */
    .card {
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        padding: 12px;
        margin-top: 12px;
    }

    .meta {
        margin: 6px 0;
    }

    .price {
        font-size: 18px;
        font-weight: 700;
    }

    .price .nds {
        font-size: 12px;
        font-weight: 400;
    }

    /* Блок изображения — таблица для вертикального центрирования */
    table.imgbox {
        width: 100%;
        border: 1px solid #eee;
        border-radius: 8px;
        border-collapse: separate;
    }

    table.imgbox td {
        height: 220px;
        text-align: center;
        vertical-align: middle;
        padding: 8px;
    }

    .imgbox img {
        max-width: 100%;
        max-height: 320px;
        display: inline-block;
    }

    /* Таблица характеристик */
    table.spec {
        width: 100%;
        border-collapse: collapse;
    }

    table.spec th,
    table.spec td {
        padding: 6px 8px;
        vertical-align: top;
    }

    table.spec th {
        text-align: left;
        width: 40%;
        color: #222;
    }

    table.spec td {
        color: #111;
    }

    table.spec tr:nth-child(odd) th,
    table.spec tr:nth-child(odd) td {
        background: #fafafa;
    }

    .footer {
        margin-top: 18px;
        font-size: 10px;
        color: #666;
    }

    /* футер рисуем в области полей: bottom ставим отрицательно на его высоту */
    .pdf-footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: -24mm;
        /* высота футера */
        height: 18mm;
        border-top: 1px solid #ddd;
        font-size: 10px;
        color: #666;
        padding: 6px 12px;
    }

    /* номер страницы / всего страниц */
    .pdf-footer .pagenum:after {
        content: counter(page);
    }

    /* Шапка — табличная раскладка для Dompdf */
    table.company-header {
        width: 100%;
        /*
        border: 1px solid #e5e5e5;
        border-collapse: separate;
        border-radius: 8px;
        margin: 8px 0 12px;
        */
    }

    table.company-header td {
        vertical-align: middle;
        text-align: center;
    }

    .ch-name {
        font-size: 18px;
        font-weight: 700;
        font-style: italic;
    }

    .ch-brand {
        font-style: italic;
        margin-top: 2px;
    }



    .ch-site a {
        color: #111;
        text-decoration: none;
    }

    /* убираем синюю ссылку */
    .ch-innkpp {
        font-weight: 700;
        margin: 4px 0;
    }

    /* тонкая разделительная линия */
    .ch-hr {
        border-bottom: 1px solid #111;
        height: 0;
        margin: 6px 0 8px;
    }

    .ch-details {
        font-size: 11px;
        line-height: 1.35;
        color: #111;
    }

    .ch-details .muted {
        color: #666;
    }

    .pdf-description {
        width: 100%;
    }

    /* все картинки внутри описания не шире контейнера */
    .pdf-description img {
        display: block;
        max-width: 100%;
        height: auto;
    }

</style>

<table class="company-header">
    <tr>
        <td>
            <div class="ch-name">{{ $c('legal_name') }}</div>
            <div class="ch-site">
                <a href="{{ $c('site_url') }}">{{ $c('site_host') }}</a>
            </div>

            <div class="ch-innkpp">
                ИНН {{ $c('inn') }}&nbsp;&nbsp;@if($c('kpp')) КПП {{ $c('kpp') }} @endif
            </div>

            <div class="ch-hr"></div>

            <div class="ch-details">
                Юр.адр.: {{ $c('legal_addr') }}
                @if ($c('phone'))
                    Тел: {{ $c('phone') }}
                @endif
                @if (!empty($bank))
                    <br />
                    р/с {{ $bank['rs'] ?? '' }}
                    к/с {{ $bank['ks'] ?? '' }}
                    БИК {{ $bank['bik'] ?? '' }}<br>
                    <span class="muted">{{ $bank['name'] ?? '' }}</span>
                @endif
            </div>
        </td>
    </tr>
</table>
<h1>ТЕХНИКО-КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ</h1>
<h2>{{ $product->name }}</h2>
<div class="meta muted">Артикул: {{ $sku }}</div>

<table class="layout" style="margin-top:12px;">
    <tr>
        <td class="col-left">
            <table class="imgbox">
                <tr>
                    <td>
                        @if ($coverSrc)
                            <img src="{{ $coverSrc }}" alt="Изображение товара">
                        @else
                            <div class="muted">Нет изображения</div>
                        @endif
                    </td>
                </tr>
            </table>


        </td>

        <td class="col-right">
            <h2>Характеристики</h2>
            @if (!empty($attributes))
                <table class="spec">
                    @foreach ($attributes as [$label, $value])
                        <tr>
                            <th>{{ $label }}</th>
                            <td>{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <div class="muted">Данные о характеристиках не найдены.</div>
            @endif
        </td>
    </tr>
</table>
<div class="card">
    <div>Цена без учета доставки:
        <span class="price">{{ $price }}</span> <span class="nds">(с НДС)</span>
        {{-- @if (property_exists($product, 'in_stock') || isset($product->in_stock))
            <span class="meta">
                {{ $product->in_stock ? ' В наличии' : ' Под заказ' }}
            </span>
        @endif --}}
    </div>
</div>
<h2>ОПИСАНИЕ</h2>
<div class="pdf-description">
    @if (!empty($descriptionHtml))
        {!! Filament\Forms\Components\RichEditor\RichContentRenderer::make($descriptionHtml)->customBlocks([
                App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock::class,
                App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock::class,
                App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock::class,
                App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock::class,
                App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock::class,
            ])->toUnsafeHtml() !!}
    @endif
</div>
<div class="footer">
    Сформировано {{ now()->format('d.m.Y H:i') }} · KRATONSHOP.RU
</div>
<div class="pdf-footer">
    {{ config('company.legal_name') }} · {{ config('company.site_host') }} · Тел. {{ config('company.phone') }} ·
    стр. <span class="pagenum"></span>
</div>
