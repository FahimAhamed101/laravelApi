<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_place_order_without_coupon_using_checkout_address_fields(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Demo Product',
            'slug' => 'demo-product',
            'qty' => 10,
            'price' => 120,
            'desc' => 'Demo',
            'thumbnail' => 'storage/images/products/demo.png',
            'status' => 1,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/store/order', [
            'name' => 'John Doe',
            'phone' => '01700000000',
            'locality' => 'Dhanmondi',
            'address' => 'House 10, Road 5',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'country' => 'Bangladesh',
            'zip' => '1205',
            'type' => 'home',
            'products' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJson([
                'message' => 'Order placed successfully.',
            ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'subtotal' => 240.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'total' => 240.00,
            'name' => 'John Doe',
            'phone' => '01700000000',
            'locality' => 'Dhanmondi',
            'address' => 'House 10, Road 5',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'country' => 'Bangladesh',
            'zip' => '1205',
            'type' => 'home',
            'status' => 'pending',
            'is_shipping_different' => 0,
        ]);

        $this->assertDatabaseHas('order_product', [
            'product_id' => $product->id,
            'qty' => 2,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'qty' => 8,
        ]);
    }

    public function test_user_cannot_place_order_when_stock_is_insufficient(): void
    {
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Low Stock Product',
            'slug' => 'low-stock-product',
            'qty' => 1,
            'price' => 150,
            'desc' => 'Demo',
            'thumbnail' => 'storage/images/products/demo.png',
            'status' => 1,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/store/order', [
            'name' => 'John Doe',
            'phone' => '01700000000',
            'locality' => 'Dhanmondi',
            'address' => 'House 10, Road 5',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'country' => 'Bangladesh',
            'zip' => '1205',
            'type' => 'home',
            'products' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                ],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['products']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'qty' => 1,
        ]);

        $this->assertSame(0, Order::count());
    }
}
