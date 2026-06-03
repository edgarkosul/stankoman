<?php

namespace App\Http\Controllers;

use App\Models\LegacyProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LegacyKratonRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $sourcePath = $this->normalizeSourcePath($request->query('path'));

        if ($sourcePath === null) {
            return response('', 404);
        }

        $legacyProduct = LegacyProduct::query()
            ->with('matchedProduct:id,slug,is_active')
            ->where('source_site', config('legacy.kraton.source_site'))
            ->where('source_path', $sourcePath)
            ->where('redirect_enabled', true)
            ->when(
                config('legacy.kraton.allowed_match_strategies') !== [],
                fn ($query) => $query->whereIn('match_strategy', config('legacy.kraton.allowed_match_strategies')),
            )
            ->first();

        $product = $legacyProduct?->matchedProduct;

        if ($product === null || ! $product->is_active) {
            return response('', 404);
        }

        return redirect()->away(
            rtrim((string) config('legacy.kraton.redirect_base_url'), '/').route('product.show', $product, false),
            (int) config('legacy.kraton.redirect_status'),
        );
    }

    private function normalizeSourcePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = '/'.ltrim($path, '/');

        if (! str_ends_with($path, '.php')) {
            return null;
        }

        return $path;
    }
}
