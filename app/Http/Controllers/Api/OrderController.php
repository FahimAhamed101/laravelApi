<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;

class OrderController extends Controller
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_PAID = 'paid';
    private const STATUS_CANCELED = 'canceled';
    private const STATUS_DELIVERED = 'delivered';
    private const CURRENCY_USD = 'usd';

    /**
     * Store new order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'locality' => 'required|string|max:255',
            'address' => 'required|string|max:1000',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'landmark' => 'sometimes|nullable|string|max:255',
            'zip' => 'required|string|max:50',
            'type' => 'sometimes|string|max:50',
            'is_shipping_different' => 'sometimes|boolean',
            'tax' => 'sometimes|numeric|min:0',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.qty' => 'required|integer|min:1',
            'products.*.coupon_id' => 'sometimes|nullable|integer',
        ]);

        $lineItems = collect($validated['products'])
            ->groupBy('product_id')
            ->map(function ($group) {
                return [
                    'product_id' => (int) $group->first()['product_id'],
                    'qty' => (int) $group->sum('qty'),
                    'coupon_id' => $group->first()['coupon_id'] ?? null,
                ];
            })
            ->values()
            ->all();

        $order = DB::transaction(function () use ($request, $validated, $lineItems) {
            $productIds = collect($lineItems)->pluck('product_id')->values()->all();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages([
                    'products' => 'Some products are no longer available.',
                ]);
            }

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $attachPayload = [];

            foreach ($lineItems as $item) {
                $product = $products->get($item['product_id']);
                $qty = (int) $item['qty'];

                if (!$product || $product->qty < $qty) {
                    throw ValidationException::withMessages([
                        'products' => 'Insufficient stock for product ID '.$item['product_id'].'.',
                    ]);
                }

                $couponId = $item['coupon_id'] ?? null;
                $lineSubtotal = (float) $product->price * $qty;
                $lineDiscount = $this->calculateDiscount($lineSubtotal, $couponId);

                $subtotal += $lineSubtotal;
                $discountTotal += $lineDiscount;

                $product->qty = (int) $product->qty - $qty;
                $product->save();

                $attachPayload[$product->id] = ['qty' => $qty];
            }

            $taxableAmount = max($subtotal - $discountTotal, 0);
            $tax = array_key_exists('tax', $validated)
                ? (float) $validated['tax']
                : round($taxableAmount * ((float) ($validated['tax_rate'] ?? 0) / 100), 2);
            $tax = max($tax, 0);

            $orderData = [
                'user_id' => $request->user()->id,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discountTotal, 2),
                'tax' => round($tax, 2),
                'total' => round($taxableAmount + $tax, 2),
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'locality' => $validated['locality'],
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'country' => $validated['country'],
                'landmark' => $validated['landmark'] ?? null,
                'zip' => $validated['zip'],
                'type' => $validated['type'] ?? 'home',
                'status' => self::STATUS_PENDING,
                'is_shipping_different' => (bool) ($validated['is_shipping_different'] ?? false),
            ];

            $order = Order::create($orderData);
            $order->products()->attach($attachPayload);

            return $order;
        });

        return response()->json([
            'message' => 'Order placed successfully.',
            'order' => $order->load('products'),
            'user' => UserResource::make($request->user()->fresh()),
        ], 201);
    }

    /**
     * Pay order using stripe
     */
    public function payOrderByStripe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'payment_intent_id' => 'sometimes|nullable|string',
            'idempotency_key' => 'required_without:payment_intent_id|string|max:255',
        ]);

        $order = Order::query()
            ->where('id', $validated['order_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found for this user.',
            ], 404);
        }

        if ($order->status === self::STATUS_PAID) {
            return response()->json([
                'message' => 'Order is already paid.',
                'status' => $order->status,
            ]);
        }

        if ($order->status === self::STATUS_DELIVERED) {
            return response()->json([
                'message' => 'Delivered order cannot be paid again.',
                'status' => $order->status,
            ], 422);
        }

        if ($order->status === self::STATUS_CANCELED) {
            return response()->json([
                'message' => 'Canceled order cannot be paid.',
                'status' => $order->status,
            ], 422);
        }

        $stripeSecret = env('STRIPE_SECRET');
        if (!$stripeSecret) {
            return response()->json([
                'message' => 'Stripe secret key is missing. Set STRIPE_SECRET in .env.',
            ], 422);
        }

        if (!class_exists(Stripe::class) || !class_exists(PaymentIntent::class)) {
            return response()->json([
                'message' => 'Stripe SDK is missing. Install stripe/stripe-php to enable card payments.',
            ], 500);
        }

        Stripe::setApiKey($stripeSecret);

        try {
            if (!empty($validated['payment_intent_id'])) {
                return $this->confirmOrderPaymentFromStripe(
                    $order,
                    (string) $validated['payment_intent_id']
                );
            }

            $idempotencyKey = trim((string) $validated['idempotency_key']);
            $paymentIntent = PaymentIntent::create([
                'amount' => $this->orderAmountInCents($order),
                'currency' => self::CURRENCY_USD,
                'description' => 'Order #'.$order->id,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'user_id' => (string) $request->user()->id,
                ],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'clientSecret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'order_id' => $order->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function handleStripeWebhook(Request $request): JsonResponse
    {
        $stripeWebhookSecret = env('STRIPE_WEBHOOK_SECRET');
        if (!$stripeWebhookSecret) {
            return response()->json([
                'message' => 'Stripe webhook secret is missing. Set STRIPE_WEBHOOK_SECRET in .env.',
            ], 500);
        }

        if (!class_exists(Webhook::class)) {
            return response()->json([
                'message' => 'Stripe SDK webhook class is missing. Install stripe/stripe-php.',
            ], 500);
        }

        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $signature, $stripeWebhookSecret);
        } catch (UnexpectedValueException $e) {
            return response()->json([
                'message' => 'Invalid webhook payload.',
            ], 400);
        } catch (SignatureVerificationException $e) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 400);
        }

        $eventType = (string) ($event->type ?? '');
        if (str_starts_with($eventType, 'payment_intent.')) {
            $paymentIntent = $event->data->object ?? null;
            if ($paymentIntent) {
                $this->syncOrderFromWebhookIntent($paymentIntent);
            }
        }

        return response()->json(['received' => true]);
    }

    protected function calculateDiscount(float $lineSubtotal, $couponId = null): float
    {
        if (!$couponId) {
            return 0;
        }

        try {
            $coupon = Coupon::find($couponId);
            if ($coupon && $coupon->checkIfValid()) {
                return $lineSubtotal * ((float) $coupon->discount / 100);
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return 0;
    }

    protected function confirmOrderPaymentFromStripe(Order $order, string $paymentIntentId): JsonResponse
    {
        $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

        if (!$this->paymentIntentBelongsToOrder($paymentIntent, $order)) {
            return response()->json([
                'message' => 'Payment intent does not belong to this order.',
            ], 422);
        }

        if ((int) $paymentIntent->amount !== $this->orderAmountInCents($order)) {
            return response()->json([
                'message' => 'Payment amount mismatch for this order.',
            ], 422);
        }

        if (strtolower((string) $paymentIntent->currency) !== self::CURRENCY_USD) {
            return response()->json([
                'message' => 'Payment currency mismatch for this order.',
            ], 422);
        }

        $this->syncOrderFromStripeIntent($order, $paymentIntent);

        if ($paymentIntent->status === 'succeeded') {
            return response()->json([
                'message' => 'Payment confirmed and order status updated.',
                'status' => self::STATUS_PAID,
                'order_id' => $order->id,
            ]);
        }

        if ($paymentIntent->status === 'canceled') {
            return response()->json([
                'message' => 'Payment was canceled and order status updated.',
                'status' => self::STATUS_CANCELED,
                'order_id' => $order->id,
            ]);
        }

        return response()->json([
            'message' => 'Payment is not completed yet.',
            'stripe_status' => $paymentIntent->status,
            'order_status' => Order::find($order->id)?->status,
        ], 202);
    }

    protected function syncOrderFromWebhookIntent($paymentIntent): void
    {
        $metadata = $this->normalizeMetadata($paymentIntent->metadata ?? null);
        $orderId = (int) ($metadata['order_id'] ?? 0);

        if ($orderId <= 0) {
            return;
        }

        $order = Order::find($orderId);
        if (!$order) {
            return;
        }

        if (!$this->paymentIntentBelongsToOrder($paymentIntent, $order)) {
            return;
        }

        if ((int) $paymentIntent->amount !== $this->orderAmountInCents($order)) {
            return;
        }

        if (strtolower((string) $paymentIntent->currency) !== self::CURRENCY_USD) {
            return;
        }

        $this->syncOrderFromStripeIntent($order, $paymentIntent);
    }

    protected function syncOrderFromStripeIntent(Order $order, $paymentIntent): void
    {
        DB::transaction(function () use ($order, $paymentIntent) {
            $lockedOrder = Order::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrder) {
                return;
            }

            $targetOrderStatus = $this->mapStripeStatusToOrderStatus((string) $paymentIntent->status);

            if ($targetOrderStatus === self::STATUS_PAID && $lockedOrder->status !== self::STATUS_PAID) {
                $lockedOrder->update([
                    'status' => self::STATUS_PAID,
                    'canceled_date' => null,
                ]);
            }

            if ($targetOrderStatus === self::STATUS_CANCELED && $lockedOrder->status !== self::STATUS_CANCELED) {
                if ($lockedOrder->status === self::STATUS_PENDING) {
                    $this->restockOrderProducts($lockedOrder);
                }

                $lockedOrder->update([
                    'status' => self::STATUS_CANCELED,
                    'canceled_date' => now()->toDateString(),
                ]);
            }
        });
    }

    protected function restockOrderProducts(Order $order): void
    {
        $order->loadMissing('products');

        foreach ($order->products as $product) {
            $qtyToRestore = (int) ($product->pivot->qty ?? 0);
            if ($qtyToRestore <= 0) {
                continue;
            }

            $lockedProduct = Product::query()
                ->where('id', $product->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedProduct) {
                continue;
            }

            $lockedProduct->qty = (int) $lockedProduct->qty + $qtyToRestore;
            $lockedProduct->save();
        }
    }

    protected function orderAmountInCents(Order $order): int
    {
        return (int) round((float) $order->total * 100);
    }

    protected function paymentIntentBelongsToOrder($paymentIntent, Order $order): bool
    {
        $metadata = $this->normalizeMetadata($paymentIntent->metadata ?? null);

        if ((int) ($metadata['order_id'] ?? 0) !== (int) $order->id) {
            return false;
        }

        if (!empty($metadata['user_id']) && (int) $metadata['user_id'] !== (int) $order->user_id) {
            return false;
        }

        return true;
    }

    protected function mapStripeStatusToOrderStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'succeeded' => self::STATUS_PAID,
            'canceled' => self::STATUS_CANCELED,
            default => self::STATUS_PENDING,
        };
    }

    protected function normalizeMetadata($metadata): array
    {
        $data = json_decode(json_encode($metadata), true);

        return is_array($data) ? $data : [];
    }
}
