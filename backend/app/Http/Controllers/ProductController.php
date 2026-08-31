<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;

final class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'products' => Product::query()->orderBy('sku')->get()->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'type' => $p->type,
                'price_minor' => $p->price_minor,
                'currency' => $p->currency,
                'image' => $p->image,
            ])->all(),
        ]);
    }
}
