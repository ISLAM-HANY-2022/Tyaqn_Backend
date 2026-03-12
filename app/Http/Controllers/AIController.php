<?php

namespace App\Http\Controllers;

use App\Models\Verification;
use App\Traits\ApiResponseTrait;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    use ApiResponseTrait;

    /*================ TEXT =================*/
    public function verifyText(Request $request)
    {
        $request->validate([
            'text_input' => 'required|string|max:'. config('uploads.max_text_chars'),
            'title' => 'nullable|string'
        ]);

        // 1. تنظيف النص: إزالة المسافات الزائدة وأي وسوم HTML لضمان تطابق الـ Hash
        $cleanText = trim(strip_tags($request->text_input));
        
        // 2. التشفير (Hashing): عمل بصمة للنص
        $hash = md5($cleanText);

        // 3. مبدأ الـ Idempotency (Atomic Locks): منع المستخدم من الضغط مرتين في نفس اللحظة
        // اللوك ده بيقفل على (الـ ID بتاع اليوزر + بصمة النص) لمدة 10 ثواني
        $lock = Cache::lock('verify_text_' . auth()->id() . '_' . $hash, 10);

        if (!$lock->get()) {
            return $this->errorResponse('جاري معالجة طلبك بالفعل، يرجى الانتظار...', 429);
        }

        try {
            // 4. التحقق من وجود النص مسبقاً في قاعدة البيانات (Caching)
            $existing = Verification::where('file_hash', $hash)->first();

            if ($existing) {
                // لو النص اتفحص قبل كدة بس عن طريق يوزر تاني، بننسخ النتيجة لليوزر الحالي عشان تظهر في الـ History بتاعه
                if ($existing->user_id !== auth()->id()) {
                    $newVerification = $existing->replicate();
                    $newVerification->user_id = auth()->id();
                    $newVerification->title = $request->title ?? 'Text Check';
                    $newVerification->created_at = now();
                    $newVerification->save();
                    
                    return $this->successResponse($newVerification, 'Text analyzed successfully (from cache)');
                }
                // لو هو نفس اليوزر، بنرجع له النتيجة القديمة فوراً
                return $this->successResponse($existing, 'Text analyzed successfully (from cache)');
            }

            // 5. استدعاء الموديل مع ميزة الـ Retry (المحاولة التلقائية عند الفشل)
            $baseUrl = config('services.ai_model.text_url'); 
            
            // جرب 3 مرات، وبين كل مرة ومرة ثانيتين (عشان لو سيرفر هجينج فيس كان نايم وبيصحى)
            $response = Http::timeout(150)->retry(3, 2000)->post($baseUrl . '/predict-text', [
                'text' => $cleanText
            ]);

            if (!$response->successful()) {
                // تسجيل الخطأ الفعلي في ملفات لارافيل للمطورين
                Log::error('AI Text Model Failed: ' . $response->body());
                return $this->errorResponse('تعذر فحص النص حالياً، حاول مرة أخرى', 500);
            }

            $aiResult = $response->json();

            // 6. حفظ النتيجة الجديدة
            $verification = Verification::create([
                'user_id'            => auth()->id(),
                'file_hash'          => $hash, // حفظ البصمة هنا مهم جداً
                'title'              => $request->title ?? 'Text Check',
                'input_data'         => $cleanText,
                'type'               => 'text',
                'result_status'      => $aiResult['status'],
                'description_result' => $aiResult['explanation']
            ]);

            return $this->successResponse($verification, 'Text analyzed successfully');

        } catch (\Exception $e) {
            Log::error('Text Verification Exception: ' . $e->getMessage());
            return $this->errorResponse('حدث خطأ داخلي في الخادم', 500);
        } finally {
            // 7. تحرير القفل فور انتهاء العملية سواء بنجاح أو فشل
            $lock->release();
        }
    }

    /*================ IMAGE =================*/
    public function verifyImage(Request $request)
    {
        $request->validate([
            'image_file' => 'required|file|mimes:jpg,jpeg,png,webp,bmp,tiff,gif,svg,avif|max:' . config('uploads.max_image_size'),
            'title' => 'nullable|string'
        ]);
    
        return $this->processMedia($request,'image_file','image','/predict-image','Tyaqn/images');
    }

    /*================ VIDEO =================*/
    public function verifyVideo(Request $request)
    {
        $request->validate([
            'video_file'=>'required|file|mimes:mp4,mov,avi,mkv,webm,flv,wmv,mpeg,mpg,3gp,m4v|max:' . config('uploads.max_video_size'),
            'title'=>'nullable|string'
        ]);

        return $this->processMedia($request,'video_file','video','/predict-video','Tyaqn/videos');
    }

    /*================ AUDIO =================*/
    public function verifyAudio(Request $request)
    {
        $request->validate([
            'audio_file'=>'required|file|mimes:mp3,wav,aac,m4a,flac,ogg,opus|max:' . config('uploads.max_audio_size'),
            'title'=>'nullable|string'
        ]);

        return $this->processMedia($request,'audio_file','audio','/verify-audio','Tyaqn/audio');
    }

    /*================ PROCESS MEDIA =================*/
    private function processMedia($request, $fileKey, $type, $endpoint, $folder)
    {
        $file = $request->file($fileKey);
        $hash = md5_file($file->getRealPath());

        $existing = Verification::where('file_hash', $hash)->first();

        if ($existing) {
            $fileHeaders = @get_headers($existing->input_data);
            if ($fileHeaders && strpos($fileHeaders[0], '200')) {
                return $this->successResponse($existing, 'File already analyzed');
            }
        }

        // تم إضافة $fileKey هنا في سطر الـ use
        return DB::transaction(function () use ($request, $file, $hash, $type, $endpoint, $folder, $existing, $fileKey) {
        
            // التعديل هنا: اختيار الرابط بناءً على النوع
            $baseUrl = match($type) {
                'image' => config('services.ai_model.url'),
                'audio' => config('services.ai_model.audio_url'),                
                'video' => config('services.ai_model.video_url'), 
                default => config('services.ai_model.url'),
            };
    
            $response = Http::timeout(150)->attach(
                $fileKey, // هيروح بـ audio_file أو image_file ولارافيل هيعرف يتعامل
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post($baseUrl . $endpoint);

            if (!$response->successful()) {
                return $this->errorResponse('AI model failed', 500);
            }

            $aiResult = $response->json();

            $upload = Cloudinary::upload($file->getRealPath(), [
                'folder' => $folder,
                'resource_type' => 'auto'
            ]);

            $data = [
                'user_id'            => auth()->id(),
                'file_hash'          => $hash,
                'title'              => $request->title ?? 'New ' . $type . ' check',
                'input_data'         => $upload->getSecurePath(),
                'type'               => $type,
                'result_status'      => $aiResult['status'] ?? 'unknown',
                'description_result' => $aiResult['explanation'] ?? 'Analysis complete'
            ];

            if ($existing) {
                $existing->update($data);
                $verification = $existing;
            } else {
                $verification = Verification::create($data);
            }

            return $this->successResponse($verification, 'File analyzed successfully');
        });
    }
    /*================ HISTORY =================*/
    public function history()
    {
        $history = Verification::where('user_id',auth()->id())
            ->latest()
            ->get();

        return $this->successResponse($history,'History retrieved');
    }
}