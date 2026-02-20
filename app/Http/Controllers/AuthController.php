<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'rememberMe' => ['sometimes', 'boolean'],
        ]);

        $credentials = $request->only(['email', 'password']);
        $guard = Auth::guard('api');
        $ttlMinutes = $request->boolean('rememberMe') ? 60 * 24 * 14 : config('jwt.ttl');

        if (! $token = $guard->setTTL($ttlMinutes)->attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'user'  => auth('api')->user(),
            'token' => $token,
            'expires_in' => $ttlMinutes * 60, // seconds
        ]);
    }

    public function me()
    {
        return response()->json(auth('api')->user());
    }

    public function logout()
    {
        $guard = Auth::guard('api');

        $guard->logout();

        return response()->json(['message' => 'Logged out']);
    }

    public function refresh()
{
    /** @var JWTGuard $guard */
    $guard = Auth::guard('api');

    return response()->json([
        'message' => 'Token refreshed',
        'data'    => [
            'token' => $guard->refresh(),
        ],
        'errors'  => null,
    ]);
}
}
