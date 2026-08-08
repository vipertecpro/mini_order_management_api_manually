<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/api/products',
        summary: 'List products',
        description: 'Paginated, 15 per page. Every filter is optional and they can be combined. '
            .'Results are cached on Redis for 5 minutes and cleared whenever a product changes.',
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'search', description: 'Partial match on name or description', in: 'query', schema: new OA\Schema(type: 'string'), example: 'keyboard'),
            new OA\Parameter(name: 'min_price', in: 'query', schema: new OA\Schema(type: 'number', format: 'float'), example: 50),
            new OA\Parameter(name: 'max_price', in: 'query', schema: new OA\Schema(type: 'number', format: 'float'), example: 200),
            new OA\Parameter(name: 'in_stock', description: 'Only products with stock left', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of products',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
        ]
    )]
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
    #[OA\Post(
        path: '/api/products',
        summary: 'Add a product',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'price', 'stock'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Mechanical Keyboard'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Blue switches.'),
                    new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0, example: 129.99),
                    new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 10),
                ]
            )
        ),
        tags: ['Products'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ]
    )]
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
    #[OA\Get(
        path: '/api/products/{id}',
        summary: 'Get a single product',
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 1),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The product',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ]
    )]
    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    /**
     * Update the specified resource in storage.
     */
    #[OA\Patch(
        path: '/api/products/{id}',
        summary: 'Update a product',
        description: 'Owner only. Send just the fields you want to change.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Mechanical Keyboard'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0, example: 49.50),
                    new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 8),
                ]
            )
        ),
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 1),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ]
    )]
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
    #[OA\Delete(
        path: '/api/products/{id}',
        summary: 'Delete a product',
        description: 'Owner only. A product that has already been ordered cannot be deleted.',
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 1),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ]
    )]
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
