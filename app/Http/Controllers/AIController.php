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
                return $this->errorResponse('Failed to connect to the text model or the model is currently unavailable', 500);
            }
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to connect to server: ' . $e->getMessage(), 500);
        }

        // حفظ العملية في قاعدة البيانات بعد نجاح رد الـ AI
        $verification = Verification::create([
            'user_id'            => auth()->id(),
            'title'              => $request->title ?? 'Check new text',
            'input_data'         => $request->text_input,
            'type'               => 'text',
            'result_status'      => $aiResult['status'],
            'description_result' => $aiResult['explanation'], 
        ]);

        return $this->successResponse($verification, 'The text was successfully checked and the result stored');
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
            return $this->successResponse($existing, 'This file has been previously checked, here is the result recorded with us.');
        }
    
        try {
            $cloudinaryUrl = env('CLOUDINARY_URL');

            // الرفع والحصول على النتيجة
            $upload = Cloudinary::upload(
                $request->file('media_file')->getRealPath(),
                ['folder' => 'Tyaqn/media']
            );
        
            // هنا السطر السحري: استخراج الرابط من نتيجة الرفع
            $uploadedFileUrl = $upload->getSecurePath(); 
        
            $baseUrl = config('services.ai_model.url');
        
            // إرسال الرابط لموديل الـ AI
            $response = Http::timeout(120)->attach(
                'media_file',
                file_get_contents($request->file('media_file')->getRealPath()),
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
                    'title'              => $request->title ?? 'New media check',
                    'input_data'         => $uploadedFileUrl, // هنا نخزن رابط السحابة بدلاً من Path المجلد المحلي
                    'type'               => $request->type,
                    'result_status'      => $aiResult['status'] ?? 'unknown',
                    'description_result' => $aiResult['explanation'] ?? 'No explanation provided',
                ]);
    
                return $this->successResponse($verification, 'The file has been scanned and successfully stored on the cloud');
            }
    
            return $this->errorResponse('The media model failed to analyze the file', 500);
    
        } catch (\Exception $e) {
            return $this->errorResponse('Connection or upload error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * دالة فحص الأصوات (Deepfake Audio Detection)
     */
    public function verifyAudio(Request $request)
    {
        $request->validate([
            'audio_file' => 'required|file|mimes:mp3,wav,aac,m4a,flac|max:20480', // حد أقصى 20 ميجا للصوت
            'title'      => 'nullable|string'
        ]);

        // 1. منع التكرار باستخدام الـ Hash
        $incomingHash = md5_file($request->file('audio_file')->getRealPath());
        $existing = Verification::where('file_hash', $incomingHash)->first();
        
        if ($existing) {
            return $this->successResponse($existing, 'This audio has been previously checked.');
        }

        try {
            // 2. رفع الملف إلى Cloudinary (مع تحديد نوع المورد كـ auto/raw للصوت)
            $upload = Cloudinary::upload(
                $request->file('audio_file')->getRealPath(),
                [
                    'folder' => 'Tyaqn/audio',
                    'resource_type' => 'auto' 
                ]
            );
            $uploadedFileUrl = $upload->getSecurePath();

            // 3. إرسال الملف لموديل الـ AI على Hugging Face
            // تأكد من إضافة AUDIO_AI_URL في ملف الـ .env
            $audioModelUrl = config('services.ai_model.audio_url'); 

            $response = Http::timeout(150)->attach(
                'file', // اسم الحقل المتوقع في FastAPI
                file_get_contents($request->file('audio_file')->getRealPath()),
                $request->file('audio_file')->getClientOriginalName()
            )->post($audioModelUrl . '/verify-audio');

            if ($response->successful()) {
                $aiResult = $response->json();

                // 4. ترجمة نتيجة الموديل لحالة مفهومة في قاعدة البيانات
                $status = ($aiResult['is_authentic'] ?? false) ? 'Real' : 'Fake';
                $confidence = $aiResult['confidence'] ?? 0;
                $label = $aiResult['label'] ?? 'Unknown';

                // 5. تخزين العملية
                $verification = Verification::create([
                    'user_id'            => auth()->id(),
                    'file_hash'          => $incomingHash,
                    'title'              => $request->title ?? 'Audio check: ' . $label,
                    'input_data'         => $uploadedFileUrl,
                    'type'               => 'audio',
                    'result_status'      => $status,
                    'description_result' => "Detection: $label, Confidence: $confidence%",
                ]);

                return $this->successResponse($verification, 'The audio has been analyzed and the result is stored.');
            }

            return $this->errorResponse('The audio AI model failed to analyze the file', 500);

        } catch (\Exception $e) {
            return $this->errorResponse('Audio processing error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * جلب تاريخ الفحوصات الخاصة بالمستخدم
     */
    public function history()
    {    
        $history = Verification::where('user_id', auth()->id())->latest()->get();
        return $this->successResponse($history, 'The test record was successfully retrieved');
    }
}