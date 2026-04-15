<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Resources\UserResource;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', function (Request $request) {
        return [
            'user' => UserResource::make($request->user()),
            'access_token' => $request->bearerToken()
        ];
    });

    Route::get('user/profile',[UserController::class,'GetUserProfile']);
    Route::post('user/logout',[UserController::class,'logout']);
    Route::match(['put', 'post'], 'user/profile/update', [UserController::class, 'UpdateUserProfile']);

   Route::post('store/order',[OrderController::class,'store']);
   Route::post('pay/order',[OrderController::class,'payOrderByStripe']);


});

Route::post('stripe/webhook', [OrderController::class, 'handleStripeWebhook'])->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
]);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('products', [ProductController::class, 'store']);
    Route::match(['put', 'post'], 'products/{product:id}', [ProductController::class, 'update']);
    Route::delete('products/{product:id}', [ProductController::class, 'destroy']);
    Route::post('products/{product:id}/delete', [ProductController::class, 'destroy']);
});

Route::get('products',[ProductController::class,'index']);
Route::get('products/{product:slug}', [ProductController::class, 'show']);
Route::post('user/register',[UserController::class,'store']);
Route::post('user/login',[UserController::class,'auth']);
