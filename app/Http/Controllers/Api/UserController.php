<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Store the new user
     */
    public function store(StoreUserRequest $request)
    {
        if($request->validated()) {
            User::create($request->validated());
            return response()->json([
                'message' => 'Account created successfully'
            ]);
        }
    }

    /**
     * Log in user
     */
    public function auth(AuthUserRequest $request)
    {
        if($request->validated()) {
            $user = User::whereEmail($request->email)->first();
            if(!$user || !Hash::check($request->password,$user->password)) {
                return response()->json([
                    'error' => 'These credentials do not match our records'
                ]);
            }else {
                return response()->json([
                    'user' => UserResource::make($user),
                    'access_token' => $user->createToken('new_user')->plainTextToken
                ]);
            }
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function GetUserProfile(Request $request)
    {
        return response()->json([
            'user' => UserResource::make($request->user()),
        ]);
    }

    public function UpdateUserProfile(Request $request)
    {
        // If profile_image key is present, it must be sent as a real uploaded file.
        if ($request->has('profile_image') && !$request->hasFile('profile_image')) {
            return response()->json([
                'message' => 'profile_image must be sent as file in form-data',
            ], 422);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:255',
            'country' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:1000',
            'zip_code' => 'sometimes|nullable|string|max:50',
            'phone_number' => 'sometimes|nullable|string|max:50',
            'profile_image' => 'sometimes|nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        $updateData = collect($validatedData)->except('profile_image')->toArray();

        if ($request->hasFile('profile_image')) {
            $currentImage = $request->user()->profile_image;
            if ($currentImage && !str_starts_with($currentImage, 'http') && File::exists(public_path($currentImage))) {
                File::delete(public_path($currentImage));
            }

            $file = $request->file('profile_image');
            $profileImageName = time() . '_' . $file->getClientOriginalName();
            $file->storeAs('images/users/', $profileImageName, 'public');

            $updateData['profile_image'] = 'storage/images/users/' . $profileImageName;
        }

        if (empty($updateData)) {
            return response()->json([
                'message' => 'No profile data provided',
                'base_url' => url('/'),
                'user' => UserResource::make($request->user()),
            ]);
        }

        $updateData['profile_completed'] = 1;
        $request->user()->update($updateData);

        return response()->json([
            'message' => 'Profile updated successfully',
            'base_url' => url('/'),
            'user' => UserResource::make($request->user()->fresh()),
        ]);
    }
}
