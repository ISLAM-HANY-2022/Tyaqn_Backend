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

       // 1. تنظيف النص لضمان دقة الـ Hashing
       $cleanText = trim(strip_tags($request->text_input));
       $hash = md5($cleanText);

       // 2. قفل الأمان لمنع تكرار الطلبات (Idempotency Lock)
       $lock = Cache::lock('verify_text_' . auth()->id() . '_' . $hash, 10);

       if (!$lock->get()) {
           return $this->errorResponse('جاري معالجة طلبك بالفعل، يرجى الانتظار...', 429);
       }

       try {
           // 3. فحص الكاش والداتابيز قبل استدعاء السيرفر (توفير ريسورسز)
           $existing = Verification::where('file_hash', $hash)->first();

           if ($existing) {
               // الخدعة: استخراج النسبة من النص المخزن في الداتابيز
               preg_match('/([0-9.]+)\s*%/', $existing->description_result, $matches);
               $ai_percentage = isset($matches[1]) ? (float)$matches[1] : 0;
               $real_percentage = 100 - $ai_percentage;

               if ($existing->user_id !== auth()->id()) {
                   $newVerification = $existing->replicate();
                   $newVerification->user_id = auth()->id();
                   $newVerification->title = $request->title ?? 'Text Check';
                   $newVerification->created_at = now();
                   $newVerification->save();
                   
                   // حقن النسب في النسخة الجديدة
                   $cachedData = $newVerification->toArray();
                   $cachedData['ai_percentage'] = $ai_percentage;
                   $cachedData['real_percentage'] = $real_percentage;

                   return $this->successResponse($cachedData, 'Text analyzed successfully (from cache)');
               }
               
               // حقن النسب في النسخة الحالية
               $cachedData = $existing->toArray();
               $cachedData['ai_percentage'] = $ai_percentage;
               $cachedData['real_percentage'] = $real_percentage;

               return $this->successResponse($cachedData, 'Text analyzed successfully (from cache)');
           }

           // 4. استدعاء سيرفر البايثون مع خاصية الـ Retry التلقائي
           $baseUrl = config('services.ai_model.text_url'); 
           
           $response = Http::timeout(150)->retry(3, 2000)->post($baseUrl . '/predict-text', [
               'text' => $cleanText
           ]);

           if (!$response->successful()) {
               Log::error('AI Text Model Failed: ' . $response->body());
               return $this->errorResponse('تعذر فحص النص حالياً، حاول مرة أخرى', 500);
           }

           $aiResult = $response->json();

           // 5. حفظ البيانات في الداتابيز بالهيكل المتوافق مع الـ Schema بتاعتك
           $verification = Verification::create([
               'user_id'            => auth()->id(),
               'file_hash'          => $hash,
               'title'              => $request->title ?? 'Text Check',
               'input_data'         => $cleanText,
               'type'               => 'text',
               'result_status'      => $aiResult['status'] ?? 'unknown', 
               'description_result' => $aiResult['explanation'] ?? 'Analysis complete'
           ]);

           // 6. حقن (Inject) نسب الذكاء الاصطناعي والبشر بشكل منفصل لمبرمج الفلاتر
           $responseData = $verification->toArray();
           $responseData['ai_percentage'] = $aiResult['ai_percentage'] ?? 0;
           $responseData['real_percentage'] = $aiResult['real_percentage'] ?? 0;

           // الرد النهائي السليم 100%
           return $this->successResponse($responseData, 'Text analyzed successfully');

       } catch (\Exception $e) {
           Log::error('Text Verification Exception: ' . $e->getMessage());
           return $this->errorResponse('حدث خطأ داخلي في الخادم', 500);
       } finally {
           // 7. فتح القفل بأمان
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

        // 1. القفل (Idempotency Lock) لمنع رفع نفس الملف مرتين في نفس اللحظة
        $lock = Cache::lock('verify_media_' . auth()->id() . '_' . $hash, 60);

        if (!$lock->get()) {
            return $this->errorResponse('جاري رفع ومعالجة الملف، يرجى الانتظار...', 429);
        }

        try {
            $existing = Verification::where('file_hash', $hash)->first();

            if ($existing) {
                // التأكد أن الملف لا يزال موجوداً على السيرفر
                $fileHeaders = @get_headers($existing->input_data);
                if ($fileHeaders && strpos($fileHeaders[0], '200')) {
                    
                    // الخدعة الذكية: استخراج النسبة المئوية القديمة من حقل النص لضمان استقرار الفلاتر من الكاش
                    preg_match('/([0-9.]+)\s*%/', $existing->description_result, $matches);
                    $ai_percentage = isset($matches[1]) ? (float)$matches[1] : 0;
                    $real_percentage = 100 - $ai_percentage;

                    // إذا كان المستخدم هو صاحب السجل الأصلي
                    if ($existing->user_id === auth()->id()) {
                        $cachedData = $existing->toArray();
                        $cachedData['ai_percentage'] = $ai_percentage;
                        $cachedData['real_percentage'] = $real_percentage;

                        return $this->successResponse($cachedData, 'File already analyzed (from cache)');
                    }
            
                    // إذا كان مستخدم جديد، أنشئ له سجلاً خاصاً به (Replicate)
                    $newEntry = $existing->replicate();
                    $newEntry->user_id = auth()->id();
                    $newEntry->title = $request->title ?? 'New ' . $type . ' check';
                    $newEntry->created_at = now();
                    $newEntry->save();
            
                    $cachedData = $newEntry->toArray();
                    $cachedData['ai_percentage'] = $ai_percentage;
                    $cachedData['real_percentage'] = $real_percentage;

                    return $this->successResponse($cachedData, 'File analyzed successfully (from cache)');
                }
            }

            return DB::transaction(function () use ($request, $file, $hash, $type, $endpoint, $folder, $existing, $fileKey) {
                
                $baseUrl = match($type) {
                    'image' => config('services.ai_model.url'),
                    'audio' => config('services.ai_model.audio_url'),                
                    'video' => config('services.ai_model.video_url'), 
                    default => config('services.ai_model.url'),
                };
        
                // استدعاء الموديل مع الـ Retry
                $response = Http::timeout(150)->retry(2, 3000)->attach(
                    $fileKey, 
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )->post($baseUrl . $endpoint);

                if (!$response->successful()) {
                    throw new \Exception("AI model ($type) failed to respond: " . $response->body());
                }

                $aiResult = $response->json();

                // رفع الملف على كلوديناري
                $upload = Cloudinary::upload($file->getRealPath(), [
                    'folder' => $folder,
                    'resource_type' => 'auto'
                ]);

                $data = [
                    'user_id'            => auth()->id(),
                    'file_hash'          => $hash,
                    'title'              => $request->title ?? 'New ' . $type . ' check',
                    'input_data'         => $upload->getSecurePath(),
                    'type'               => 'type',
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

                // حقن النسب بشكل مباشر لرد الـ API النهائي لراحة الفلاتر
                $responseData = $verification->toArray();
                $responseData['ai_percentage'] = $aiResult['ai_percentage'] ?? 0;
                $responseData['real_percentage'] = $aiResult['real_percentage'] ?? 0;

                return $this->successResponse($responseData, 'File analyzed successfully');
            });

        } catch (\Exception $e) {
            Log::error("Media Verification Error ($type): " . $e->getMessage());
            return $this->errorResponse('حدث خطأ أثناء فحص الملف', 500);
        } finally {
            $lock->release(); // فك القفل دائماً
        }
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