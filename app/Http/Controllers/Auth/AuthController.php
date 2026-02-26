<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function register(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => [
                'required','string','min:5','max:20',
                'regex:/[A-Z]/','regex:/[0-9]/',
                'confirmed'
            ],
        ]);
    
        if ($validator->fails()) {
            // هنا نأخذ أول رسالة خطأ فقط مهما كان عدد الأخطاء
            $errorMessage = $validator->errors()->first();
    
            // نرسل الرسالة في خانة الـ message ونضع الـ errors بـ null لتنظيف الرد
            return $this->errorResponse($errorMessage, null, 422);
        }

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('mobile-token')->plainTextToken;

        return $this->successResponse([
            'user'  => $user,
            'token' => $token
        ], 'Registered successfully', 201);
    }

    public function login(Request $request)
    {
        // تم التعديل هنا لاستخدام الـ Validator يدوياً لتوحيد الرد
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), null, 422);
        }

        $user = User::where('email', $request->email)->first();

        // هنا نستخدم نفس التكنيك في رسالة الخطأ
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->errorResponse('The email or password is incorrect', null, 401);
        }
        $user->tokens()->delete();
        $token = $user->createToken('mobile-token')->plainTextToken;

        return $this->successResponse([
            'user'  => $user,
            'token' => $token
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        // حذف التوكن الحالي للمستخدم
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logged out successfully');
    }
}
