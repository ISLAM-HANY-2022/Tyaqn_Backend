<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Mail\ResetPasswordMail;
use App\Traits\ApiResponseTrait;

class PasswordController extends Controller
{
    use ApiResponseTrait;

    // 1. إرسال الكود للإيميل
    public function sendResetCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);
    
        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), null, 422);
        }
    
        //تحقق لو اليوزر طلب كود في أقل من دقيقة
        $existingRecord = DB::table('password_reset_tokens')->where('email', $request->email)->first();
        if ($existingRecord && now()->subMinute()->lt($existingRecord->created_at)) {
            return $this->errorResponse('Please wait a minute before requesting another code.', null, 429);
        }
    
        $code = rand(1000, 9999);
        
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($code),
                'created_at' => now()
            ]
        );
    
        Mail::to('islamhany.cv@gmail.com')->send(new ResetPasswordMail($code));
    
        return $this->successResponse(['email' => $request->email], 'OTP sent successfully');
    }

    // 2. التحقق من الكود
    public function verifyResetCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'code'  => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), null, 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        // التأكد من صحة الكود وصلاحيته (15 دقيقة)
        if (!$record || !Hash::check($request->code, $record->token) || 
            now()->subMinutes(15)->gt($record->created_at)) {
            return $this->errorResponse('Invalid or expired code', null, 422);
        }

        return $this->successResponse(null, 'The code is correct, you can now change your password.');
    }

    // 3. تعيين كلمة المرور الجديدة
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email|exists:users,email',
            'code'     => 'required',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed', // هذا هو المسؤول عن حقل التأكيد
                'regex:/[A-Z]/',      // يجب وجود حرف كبير (كما في الصورة)
                'regex:/[0-9]/',// يجب وجود رقم (كما في الصورة)
                /*'regex:/[@$!%*#?&]/', // يجب وجود رمز خاص (كما في الصورة)*/
            ],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), null, 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || !Hash::check($request->code, $record->token)) {
            return $this->errorResponse('Unauthorized process or incorrect code', null, 422);
        }

        // تحديث كلمة المرور
        $user = User::where('email', $request->email)->first();
        $user->update(['password' => Hash::make($request->password)]);

        // حذف الكود المستخدم لزيادة الأمان
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return $this->successResponse(null, 'Password changed successfully');
    }
}