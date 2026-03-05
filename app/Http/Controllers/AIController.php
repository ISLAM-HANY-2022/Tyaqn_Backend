<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Verification; 
use Illuminate\Support\Facades\Http;
use App\Traits\UploadImageTrait;
use App\Traits\ApiResponseTrait;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class AIController extends Controller
{
    use ApiResponseTrait, UploadImageTrait;

    /**
     * دالة فحص النصوص
     * تستلم النص من Flutter وترسله لموديل الـ AI ثم تخزن النتيجة.
     */
    public function verifyText(Request $request) {
        $request->validate([
            'text_input' => 'required|string',
            'title'      => 'nullable|string' 
        ]);

        try {            
            // سحب الرابط من الإعدادات المركزية
            $baseUrl = config('services.ai_model.url');

            // إرسال الطلب لموديل الذكاء الاصطناعي
            $response = Http::post($baseUrl . '/predict-text', [
                'text' => $request->text_input,
                'type' => 'text'
            ]);

            if ($response->successful()) {
                $aiResult = $response->json();
            } else {
                return $this->errorResponse('فشل الاتصال بموديل النصوص أو الموديل غير متاح حالياً', 500);
            }
        } catch (\Exception $e) {
            return $this->errorResponse('خطأ في الاتصال بالسيرفر: ' . $e->getMessage(), 500);
        }

        // حفظ العملية في قاعدة البيانات بعد نجاح رد الـ AI
        $verification = Verification::create([
            'user_id'            => auth()->id(),
            'title'              => $request->title ?? 'فحص نص جديد',
            'input_data'         => $request->text_input,
            'type'               => 'text',
            'result_status'      => $aiResult['status'],
            'description_result' => $aiResult['explanation'], 
        ]);

        return $this->successResponse($verification, 'تم فحص النص بنجاح وتخزين النتيجة');
    }

    /**
     * دالة فحص الصور والفيديوهات
     * تستخدم الـ Hash لمنع تكرار فحص نفس الملف وتوفير الموارد.
     */
    public function verifyMedia(Request $request)
    {
        $request->validate([
            'media_file' => 'required|file|mimes:jpg,jpeg,png,mp4|max:102400', 
            'type'       => 'required|in:image,video',
            'title'      => 'nullable|string'
        ]);
    
        // 1. عمل Hash للملف من الـ Path المؤقت (مهم جداً للتحقق من التكرار)
        $incomingHash = md5_file($request->file('media_file')->getRealPath());
    
        // 2. التحقق من وجود فحص سابق لنفس الملف في الداتا بيز
        $existing = Verification::where('file_hash', $incomingHash)->first();
        if ($existing) {
            return $this->successResponse($existing, 'هذا الملف تم فحصه مسبقاً، إليك النتيجة المسجلة لدينا.');
        }
    
        try {
            // 3. الرفع إلى Cloudinary بدلاً من الـ Trait القديم
            // سيتم تخزين الملف في مجلد اسمه Tyaqn على السحابة
            $upload = Cloudinary::upload($request->file('media_file')->getRealPath(), [
                'folder' => 'Tyaqn/media'
            ]);
            
            $uploadedFileUrl = $upload->getSecurePath(); // هذا هو الرابط الذي يبدأ بـ https
    
            // 4. إعداد الاتصال بموديل الـ AI
            $baseUrl = config('services.ai_model.url');
    
            // نرسل محتوى الملف للـ AI (باستخدام الرابط السحابي لضمان الوصول)
            $response = Http::attach(
                'media_file', 
                file_get_contents($uploadedFileUrl), 
                $request->file('media_file')->getClientOriginalName()
            )->post($baseUrl . '/predict-media', [
                'type' => $request->type
            ]);
    
            if ($response->successful()) {
                $aiResult = $response->json();
    
                // 5. تخزين البيانات في قاعدة البيانات
                $verification = Verification::create([
                    'user_id'            => auth()->id(),
                    'file_hash'          => $incomingHash,
                    'title'              => $request->title ?? 'فحص ميديا جديد',
                    'input_data'         => $uploadedFileUrl, // هنا نخزن رابط السحابة بدلاً من Path المجلد المحلي
                    'type'               => $request->type,
                    'result_status'      => $aiResult['status'] ?? 'unknown',
                    'description_result' => $aiResult['explanation'] ?? 'No explanation provided',
                ]);
    
                return $this->successResponse($verification, 'تم فحص الملف وتخزينه على السحابة بنجاح');
            }
    
            return $this->errorResponse('فشل موديل الميديا في تحليل الملف', 500);
    
        } catch (\Exception $e) {
            return $this->errorResponse('خطأ في الاتصال أو الرفع: ' . $e->getMessage(), 500);
        }
    }

    /**
     * جلب تاريخ الفحوصات الخاصة بالمستخدم
     */
    public function history()
    {    
        $history = Verification::where('user_id', auth()->id())->latest()->get();
        return $this->successResponse($history, 'تم جلب سجل الفحوصات بنجاح');
    }
}