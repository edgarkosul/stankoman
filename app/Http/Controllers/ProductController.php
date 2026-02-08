<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ProductController extends Controller
{
    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'categories',
            'attributeValues.attribute',
            'attributeOptions.attribute',
        ]);

        return view('pages.product.show', [
            'product' => $product,
        ]);
    }
}
