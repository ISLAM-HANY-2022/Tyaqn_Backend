<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Traits\UploadImageTrait;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Http;
class UserController extends Controller
{
    use ApiResponseTrait, UploadImageTrait;

    public function profile(Request $request){
            $user = $request->user();
            return $this->successResponse($user,'User profile retrieved successfully');
    }
    
    public function updateProfile(Request $request){

            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'name'          => 'sometimes|string|max:255',
                'email'         => 'sometimes|email|unique:users,email,' . $user->id,
                'bio'           => 'sometimes|nullable|string',
                'profile_image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->first(), 422);
            }

            // تحديث البيانات النصية
            $user->fill($request->only(['name', 'email', 'bio']));

            // منطق رفع الصورة الجديد
            if ($request->hasFile('profile_image')) {
                
                // 1. إذا كان للمستخدم صورة قديمة، نقوم بحذفها من كلاودناري
                if ($user->profile_image) {
                    $this->deleteFromCloudinary($user->profile_image);
                }

                // 2. رفع الصورة الجديدة للمجلد المطلوب
                // المجلد هيكون: Tyaqn/Profiles Images
                $imageUrl = $this->uploadToCloudinary($request->file('profile_image'), 'Profiles_Images');

                // 3. حفظ الرابط الجديد في قاعدة البيانات
                $user->profile_image = $imageUrl;
            }

            $user->save();

            return $this->successResponse($user, 'Profile updated successfully');
        }

        public function deleteAccount(Request $request) {
            // 1. الحصول على المستخدم الحالي عبر التوكن
            $user = $request->user();
        
            if ($user) {
                // 2. مسح جميع التوكنات الخاصة به لمنع أي دخول مستقبلي بالتوكن القديم
                $user->tokens()->delete();
        
                // 3. حذف المستخدم نهائياً من جدول users
                $user->delete();
        
                return $this->successResponse(null, 'Account Deleted successfully');
            }
        
            return $this->errorResponse(null, 'this account doesn`t exist');
        }
}
