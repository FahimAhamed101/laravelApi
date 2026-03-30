<?php

namespace App\Http\Controllers\Api;

use App\Models\Size;
use App\Models\Color;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;

class ProductController extends Controller
{
    /**
     * Get all the products
     */
    public function index()
    {
        return ProductResource::collection(
            Product::with(['colors','sizes','reviews'])->latest()->get())
            ->additional([
                'colors' => Color::has('products')->get(),
                'sizes' => Size::has('products')->get(),
            ]);
    }

    /**
     * Store a newly created product (admin only)
     */
    public function store(Request $request)
    {
        $this->normalizeRelationInputs($request);

        $validatedData = $request->validate($this->productValidationRules());
        $payload = $this->buildProductPayload($request, $validatedData);
        $product = Product::create($payload);

        $this->syncProductRelations($product, $validatedData);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => ProductResource::make($product->load(['colors', 'sizes', 'reviews'])),
        ], 201);
    }

    /**
     * Update product (admin only)
     */
    public function update(Request $request, Product $product)
    {
        $this->normalizeRelationInputs($request);

        $validatedData = $request->validate($this->productValidationRules($product));
        $payload = $this->buildProductPayload($request, $validatedData, $product);

        if (!empty($payload)) {
            $product->update($payload);
        }

        $this->syncProductRelations($product, $validatedData);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => ProductResource::make($product->fresh()->load(['colors', 'sizes', 'reviews'])),
        ]);
    }

    /**
     * Delete product (admin only)
     */
    public function destroy(Product $product)
    {
        $this->deleteProductImages($product);
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    /**
     * Get product by slug
     */
    public function show(Product $product)
    {
        if(!$product) {
            abort(404);
        }
        return ProductResource::make(
            $product->load(['colors','sizes','reviews']));
    }

    /**
     * Filter products by color
     */
    public function filterProductsByColor(Color $color)
    {
        return ProductResource::collection(
            $color->products()->with(['colors','sizes','reviews'])
            ->latest()->get())
            ->additional([
                'colors' => Color::has('products')->get(),
                'sizes' => Size::has('products')->get(),
            ]);
    }

    /**
     * Filter products by size
     */
    public function filterProductsBySize(Size $size)
    {
        return ProductResource::collection(
            $size->products()->with(['colors','sizes','reviews'])
            ->latest()->get())
            ->additional([
                'colors' => Color::has('products')->get(),
                'sizes' => Size::has('products')->get(),
            ]);
    }

    /**
     * Search for products by term
     */
    public function findProductsByTerm($searchTerm)
    {
        return ProductResource::collection(
            Product::where('name', 'LIKE', '%'.$searchTerm.'%')
            ->with(['colors','sizes','reviews'])
            ->latest()->get())
            ->additional([
                'colors' => Color::has('products')->get(),
                'sizes' => Size::has('products')->get(),
            ]);
    }

    private function productValidationRules(?Product $product = null): array
    {
        $isUpdate = $product !== null;

        return [
            'name' => ($isUpdate ? 'sometimes' : 'required').'|string|max:255',
            'slug' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('products', 'slug')->ignore($product?->id),
            ],
            'qty' => ($isUpdate ? 'sometimes' : 'required').'|integer|min:0',
            'price' => ($isUpdate ? 'sometimes' : 'required').'|integer|min:0',
            'desc' => ($isUpdate ? 'sometimes' : 'required').'|string',
            'thumbnail' => ($isUpdate ? 'sometimes' : 'required').'|image|mimes:jpg,jpeg,png,webp|max:4096',
            'first_image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'second_image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'third_image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'status' => 'sometimes|boolean',
            'colors' => 'sometimes|array',
            'colors.*' => 'integer|exists:colors,id',
            'sizes' => 'sometimes|array',
            'sizes.*' => 'integer|exists:sizes,id',
        ];
    }

    private function normalizeRelationInputs(Request $request): void
    {
        foreach (['colors', 'sizes'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([
                    $field => collect(explode(',', $value))
                        ->map(fn ($item) => trim($item))
                        ->filter(fn ($item) => $item !== '')
                        ->values()
                        ->all(),
                ]);
            }
        }
    }

    private function buildProductPayload(Request $request, array $validatedData, ?Product $existingProduct = null): array
    {
        $payload = collect($validatedData)->except([
            'colors',
            'sizes',
            'thumbnail',
            'first_image',
            'second_image',
            'third_image',
        ])->toArray();

        foreach (['thumbnail', 'first_image', 'second_image', 'third_image'] as $imageField) {
            if ($request->hasFile($imageField)) {
                if ($existingProduct && $existingProduct->{$imageField}) {
                    $this->deleteImageFile($existingProduct->{$imageField});
                }

                $payload[$imageField] = $this->storeImage($request->file($imageField));
            }
        }

        return $payload;
    }

    private function syncProductRelations(Product $product, array $validatedData): void
    {
        if (array_key_exists('colors', $validatedData)) {
            $product->colors()->sync($validatedData['colors'] ?? []);
        }

        if (array_key_exists('sizes', $validatedData)) {
            $product->sizes()->sync($validatedData['sizes'] ?? []);
        }
    }

    private function storeImage(UploadedFile $file): string
    {
        $fileName = time().'_'.Str::random(8).'_'.$file->getClientOriginalName();
        $file->storeAs('images/products', $fileName, 'public');

        return 'storage/images/products/'.$fileName;
    }

    private function deleteProductImages(Product $product): void
    {
        foreach (['thumbnail', 'first_image', 'second_image', 'third_image'] as $imageField) {
            $this->deleteImageFile($product->{$imageField});
        }
    }

    private function deleteImageFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $fullPath = public_path($path);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
