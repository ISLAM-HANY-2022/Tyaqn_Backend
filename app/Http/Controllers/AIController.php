<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Verification; 
use Illuminate\Support\Facades\Http;
use App\Traits\UploadImageTrait;
use App\Traits\ApiResponseTrait;

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
            'media_file' => 'required|file|mimes:jpg,jpeg,png,mp4|max:102400', // حد أقصى 100 ميجا
            'type'       => 'required|in:image,video',
            'title'      => 'nullable|string'
        ]);

        // عمل Hash للملف للتأكد من بصمته قبل الرفع
        $incomingHash = md5_file($request->file('media_file')->getRealPath());

        // التحقق من وجود فحص سابق لنفس الملف
        $existing = Verification::where('file_hash', $incomingHash)->first();
        if ($existing) {
            return $this->successResponse($existing, 'هذا الملف تم فحصه مسبقاً، إليك النتيجة المسجلة لدينا.');
        }

        // رفع الملف إلى مجلد public/uploads/media باستخدام الـ Trait
        $uploadData = $this->uploadFile($request->file('media_file'), 'media');
        $filePath = $uploadData['path'];
        $fileHash = $uploadData['hash'];
        $fullPath = public_path($filePath);

        try {           
            
            $baseUrl = config('services.ai_model.url');

            // إرسال الملف الفعلي لموديل الـ AI
            $response = Http::attach(
                'media_file', 
                file_get_contents($fullPath), 
                $request->file('media_file')->getClientOriginalName()
            )->post($baseUrl . '/predict-media', [
                'type' => $request->type
            ]);

            if ($response->successful()) {
                $aiResult = $response->json();

                // تخزين بيانات الفحص في قاعدة البيانات
                $verification = Verification::create([
                    'user_id'            => auth()->id(),
                    'file_hash'          => $fileHash,
                    'title'              => $request->title ?? 'فحص ميديا جديد',
                    'input_data'         => $filePath,
                    'type'               => $request->type,
                    'result_status'      => $aiResult['status'],
                    'description_result' => $aiResult['explanation'],
                ]);

                return $this->successResponse($verification, 'تم فحص الملف وتخزينه بنجاح');
            }

            if (file_exists($fullPath)) { unlink($fullPath); }
            return $this->errorResponse('فشل موديل الميديا في تحليل الملف', 500);

        } catch (\Exception $e) {
           
            if (file_exists($fullPath)) { unlink($fullPath); }
            return $this->errorResponse('خطأ في الاتصال: ' . $e->getMessage(), 500);
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