<div class="space-y-4">
    @include('pages.product.partials.gallery', ['gallery' => $gallery])

    <div class="lg:hidden">
        @include('pages.product.partials.summary', ['summary' => $summary])
    </div>
</div>
