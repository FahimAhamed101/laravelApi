<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Resources\UserResource;

Route::middleware('auth:sanctum')->group(function() {
    Route::get('user', function (Request $request) {
        return [
            'user' => UserResource::make($request->user()),
            'access_token' => $request->bearerToken()
        ];
    });

    Route::get('user/profile',[UserController::class,'GetUserProfile']);
    Route::post('user/logout',[UserController::class,'logout']);
    Route::match(['put', 'post'], 'user/profile/update', [UserController::class, 'UpdateUserProfile']);

})->middleware('auth:sanctum');


Route::post('user/register',[UserController::class,'store']);
Route::post('user/login',[UserController::class,'auth']);
