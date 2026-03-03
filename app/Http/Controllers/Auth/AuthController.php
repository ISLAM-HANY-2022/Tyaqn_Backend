<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function register(Request $request)
    {
        // 1. التحقق من الشروط (الباسورد حسب الفيجما)
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => [
                'required','string','min:5','max:20',
                'regex:/[A-Z]/','regex:/[0-9]/','regex:/[@$!%*#?&]/',
                'confirmed'
            ],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), null, 422);
        }

        // يبدأ الـ Transaction هنا لضمان النظافة
        DB::beginTransaction();

        try {
            // 2. إنشاء المستخدم
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // 3. توليد كود الـ OTP وحفظه مؤقتاً
            $code = rand(1000, 9999);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($code), 'created_at' => now()]
            );

            // 4. محاولة إرسال الإيميل (لو الإيميل وهمي العملية هتفشل هنا)
            Mail::to('islamhany.cv@gmail.com')->send(new \App\Mail\ResetPasswordMail($code)); 

            // 5. إنشاء التوكن (بما إننا في خطوة واحدة)
            $token = $user->createToken('mobile-token')->plainTextToken;

            // لو كل شيء تمام، ثبت البيانات في الداتابيز
            DB::commit();

            return $this->successResponse([
                'user'  => $user,
                'token' => $token
            ], 'Registered successfully. OTP sent to verify your email.', 201);

        } catch (\Exception $e) {
            // لو الإيميل وهمي أو السيرفر وقع، امسح اليوزر فوراً كأن شيئاً لم يكن
            DB::rollBack();
            return $this->errorResponse('Registration failed: The email address does not exist or service is unavailable.', null, 500);
        }
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
