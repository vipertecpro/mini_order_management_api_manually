<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Listings are read far more often than products change, so the
        // result of each filter combination is cached for a few minutes
        // and dropped whenever a product (or its stock) is touched.
        $products = Cache::tags('products')->remember(
            'products.'.md5($request->fullUrl()),
            now()->addMinutes(5),
            fn () => Product::query()
                ->when($request->search, function ($query, $search) {
                    $query->where(function ($query) use ($search) {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->when($request->min_price, fn ($query, $price) => $query->where('price', '>=', $price))
                ->when($request->max_price, fn ($query, $price) => $query->where('price', '<=', $price))
                ->when($request->boolean('in_stock'), fn ($query) => $query->where('stock', '>', 0))
                ->latest()
                ->paginate(15)
        );

        return ProductResource::collection($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        $product = $request->user()->products()->create(
            $request->validated()
        );

        $this->forgetCachedProducts();

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        abort_unless(
            $product->created_by === $request->user()->id,
            403
        );
        $product->update($request->validated());

        $this->forgetCachedProducts();

        return new ProductResource($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Product $product)
    {
        abort_unless(
            $product->created_by === $request->user()->id,
            403
        );
        $product->delete();

        $this->forgetCachedProducts();

        return response()->noContent();
    }

    /**
     * Throw away every cached product listing.
     */
    private function forgetCachedProducts(): void
    {
        Cache::tags('products')->flush();
    }
}
