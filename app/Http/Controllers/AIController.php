<?php

namespace App\Http\Controllers;

use App\Models\Verification; 
use App\Traits\ApiResponseTrait;
use App\Traits\UploadImageTrait;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

        $file = $request->file('media_file');
        $incomingHash = md5_file($file->getRealPath());

        $existing = Verification::where('file_hash', $incomingHash)->first();
        if ($existing) {
            return $this->successResponse($existing, 'Previous result found for this file.');
        }

        return DB::transaction(function () use ($request, $file, $incomingHash) {
            try {
                $baseUrl = config('services.ai_model.url');

                // فحص الـ AI أولاً
                $response = Http::timeout(120)->attach(
                    'media_file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )->post($baseUrl . '/predict-media', ['type' => $request->type]);

                if (!$response->successful()) {
                    throw new \Exception('Media AI model failed to respond.');
                }

                $aiResult = $response->json();

                // الرفع للسحابة بعد التأكد من الموديل
                $upload = Cloudinary::upload($file->getRealPath(), [
                    'folder' => 'Tyaqn/media',
                    'resource_type' => 'auto'
                ]);

                $verification = Verification::create([
                    'user_id'            => auth()->id(),
                    'file_hash'          => $incomingHash,
                    'title'              => $request->title ?? 'New ' . $request->type . ' check',
                    'input_data'         => $upload->getSecurePath(),
                    'type'               => $request->type,
                    'result_status'      => $aiResult['status'] ?? 'unknown',
                    'description_result' => $aiResult['explanation'] ?? 'Analysis complete',
                ]);

                return $this->successResponse($verification, 'Media processed and stored.');

            } catch (\Exception $e) {
                return $this->errorResponse('Error: ' . $e->getMessage(), 500);
            }
        });
    }

    /**
     * دالة فحص الأصوات (Deepfake Audio Detection)
     */
    public function verifyAudio(Request $request)
    {
        $request->validate([
            'audio_file' => 'required|file|mimes:mp3,wav,aac,m4a,flac|max:20480',
            'title'      => 'nullable|string'
        ]);

        // 1. التحقق من الـ Hash (خارج الترانزاكشن لتوفير الوقت)
        $file = $request->file('audio_file');
        $incomingHash = md5_file($file->getRealPath());
        
        $existing = Verification::where('file_hash', $incomingHash)->first();
        if ($existing) {
            return $this->successResponse($existing, 'This audio has been previously checked.');
        }

        // 2. بدأ الترانزاكشن لضمان سلامة البيانات
        return \DB::transaction(function () use ($request, $file, $incomingHash) {
            try {
                $audioModelUrl = config('services.ai_model.audio_url');

                // 3. الخطوة الأهم: نكلم الـ AI أولاً بالملف المحلي (Local Path) 
                // مش محتاجين نرفعه لكلاودناري لسه عشان لو الموديل واقع موفر وقت
                $response = Http::timeout(150)->attach(
                    'file', 
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )->post($audioModelUrl . '/verify-audio');

                if (!$response->successful()) {
                    throw new \Exception('AI Model failed or currently unavailable.');
                }

                $aiResult = $response->json();

                // 4. الآن بما أن الـ AI رد بنجاح، نرفع لكلاودناري
                $upload = Cloudinary::upload($file->getRealPath(), [
                    'folder' => 'Tyaqn/audio',
                    'resource_type' => 'auto'
                ]);
                $uploadedFileUrl = $upload->getSecurePath();

                // 5. ترجمة النتيجة وحفظها
                $status = ($aiResult['is_authentic'] ?? false) ? 'Real' : 'Fake';
                $confidence = $aiResult['confidence'] ?? 0;

                $verification = Verification::create([
                    'user_id'            => auth()->id(),
                    'file_hash'          => $incomingHash,
                    'title'              => $request->title ?? 'Audio Check: ' . $aiResult['label'],
                    'input_data'         => $uploadedFileUrl,
                    'type'               => 'audio',
                    'result_status'      => $status,
                    'description_result' => "Detection: {$aiResult['label']}, Confidence: $confidence%",
                ]);

                return $this->successResponse($verification, 'Check completed and data synced.');

            } catch (\Exception $e) {
                // أي خطأ هنا هيعمل Rollback تلقائي لأي حاجة حصلت جوا الـ transaction
                return $this->errorResponse('Process failed: ' . $e->getMessage(), 500);
            }
        });
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