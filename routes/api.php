<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
Route::middleware('auth:sanctum')->group(function() {
    Route::get('user', function (Request $request) {
        return [
            'user' => UserResource::make($request->user()),
            'access_token' => $request->bearerToken()
        ];
    });
    Route::post('user/logout',[UserController::class,'logout']);
})->middleware('auth:sanctum');


Route::post('user/register',[UserController::class,'store']);
Route::post('user/login',[UserController::class,'auth']);
