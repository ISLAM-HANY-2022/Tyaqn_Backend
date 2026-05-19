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
           'video_file' => 'required|file|mimes:mp4,mov,avi,mkv,webm,flv,wmv,mpeg,mpg,3gp,m4v|max:' . config('uploads.max_video_size'),
           'title'      => 'nullable|string'
       ]);

       $file = $request->file('video_file');
       $hash = md5_file($file->getRealPath());

       $lock = Cache::lock('verify_video_' . auth()->id() . '_' . $hash, 60);
       if (!$lock->get()) {
           return $this->errorResponse('جاري معالجة الفيديو بالفعل، يرجى الانتظار...', 429);
       }

       try {
           $existing = Verification::where('file_hash', $hash)->first();
           
           if ($existing) {
               if ($existing->result_status !== 'Error' && $existing->result_status !== 'pending') {
                   // الكود القديم لإرجاع الكاش السليم...
                   preg_match('/([0-9.]+)\s*%/', $existing->description_result, $matches);
                   $ai_percentage = isset($matches[1]) ? (float)$matches[1] : 0;
                   $real_percentage = 100 - $ai_percentage;

                   if ($existing->user_id === auth()->id()) {
                       $cachedData = $existing->toArray();
                       $cachedData['ai_percentage'] = $ai_percentage;
                       $cachedData['real_percentage'] = $real_percentage;
                       return $this->successResponse($cachedData, 'Video already analyzed (from cache)');
                   }

                   $newEntry = $existing->replicate();
                   $newEntry->user_id = auth()->id();
                   $newEntry->title = $request->title ?? 'Video check';
                   $newEntry->created_at = now();
                   $newEntry->save();

                   $cachedData = $newEntry->toArray();
                   $cachedData['ai_percentage'] = $ai_percentage;
                   $cachedData['real_percentage'] = $real_percentage;
                   return $this->successResponse($cachedData, 'Video analyzed successfully (from cache)');
               } elseif ($existing->result_status === 'pending') {
                   return $this->successResponse($existing->toArray(), 'هذا الفيديو قيد المعالجة حالياً.', 202);
               }
               
               // الحــــــــل ههنا: لو الملف موجود وحالته Error، أعد استخدام السجل القديم ولا تنشئ سجلاً جديداً
               if ($existing->result_status === 'Error') {
                   $existing->update([
                       'title'              => $request->title ?? $existing->title,
                       'input_data'         => 'pending_upload',
                       'result_status'      => 'pending',
                       'description_result' => 'إعادة محاولة فحص الفيديو في الخلفية، ستظهر النتيجة فوراً...'
                   ]);
                   $verification = $existing;
               }
           } else {
               // لو الملف أول مرة يترفع أصلاً، ننشئ سجل جديد
               $verification = Verification::create([
                   'user_id'            => auth()->id(),
                   'file_hash'          => $hash,
                   'title'              => $request->title ?? 'Video check',
                   'input_data'         => 'pending_upload', 
                   'type'               => 'video',
                   'result_status'      => 'pending',
                   'description_result' => 'جاري نقل وفحص الفيديو في الخلفية، ستظهر النتيجة فوراً عند الانتهاء...'
               ]);
           }

           $tempName = time() . '_' . rand(100, 999) . '_' . $file->getClientOriginalName();
           $file->storeAs('temp_media', $tempName, 'local'); 
           $tempPath = storage_path('app/temp_media/' . $tempName);

           \App\Jobs\ProcessMediaVerification::dispatch(
               $verification->id,
               $tempPath,
               'video_file',
               'video',
               '/predict-video',
               'Tyaqn/videos',
               $file->getClientOriginalName()
           );

           return $this->successResponse($verification->toArray(), 'تم استلام ملف الفيديو بنجاح، وجاري إعادة الفحص.', 202);

       } catch (\Exception $e) {
           Log::error("Video File Verification Error: " . $e->getMessage());
           return $this->errorResponse('حدث خطأ أثناء فحص الملف', 500);
       } finally {
           $lock->release();
       }
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

        $lock = Cache::lock('verify_media_' . auth()->id() . '_' . $hash, 60);
        if (!$lock->get()) {
            return $this->errorResponse('جاري رفع ومعالجة الملف، يرجى الانتظار...', 429);
        }

        try {
            $existing = Verification::where('file_hash', $hash)->first();

            if ($existing) {
                if ($existing->result_status !== 'Error' && $existing->result_status !== 'pending') {
                    // ارجاع الكاش السليم القديم...
                    $fileHeaders = @get_headers($existing->input_data);
                    if ($fileHeaders && strpos($fileHeaders[0], '200')) {
                        preg_match('/([0-9.]+)\s*%/', $existing->description_result, $matches);
                        $ai_percentage = isset($matches[1]) ? (float)$matches[1] : 0;
                        $real_percentage = 100 - $ai_percentage;

                        if ($existing->user_id === auth()->id()) {
                            $cachedData = $existing->toArray();
                            $cachedData['ai_percentage'] = $ai_percentage;
                            $cachedData['real_percentage'] = $real_percentage;
                            return $this->successResponse($cachedData, 'File already analyzed (from cache)');
                        }
                
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
                } elseif ($existing->result_status === 'pending') {
                    return $this->successResponse($existing->toArray(), 'هذا الملف قيد المعالجة حالياً، يرجى الانتظار.', 202);
                }

                // الحــــــــل ههنا أيضاً: تحديث السجل الفاشل القديم بدلاً من تكراره
                if ($existing->result_status === 'Error') {
                    $existing->update([
                        'title'              => $request->title ?? $existing->title,
                        'input_data'         => 'pending_upload',
                        'result_status'      => 'pending',
                        'description_result' => 'جاري إعادة فحص الملف ومعالجة البيانات في الخلفية...'
                    ]);
                    $verification = $existing;
                }
            } else {
                // إنشاء سجل جديد تماماً لأول مرة
                $verification = Verification::create([
                    'user_id'            => auth()->id(),
                    'file_hash'          => $hash,
                    'title'              => $request->title ?? 'New ' . $type . ' check',
                    'input_data'         => 'pending_upload', 
                    'type'               => $type,
                    'result_status'      => 'pending',
                    'description_result' => 'جاري فحص الملف ومعالجة البيانات في الخلفية...'
                ]);
            }

            $tempName = time() . '_' . rand(100, 999) . '_' . $file->getClientOriginalName();
            $file->storeAs('temp_media', $tempName, 'local'); 
            $tempPath = storage_path('app/temp_media/' . $tempName);

            \App\Jobs\ProcessMediaVerification::dispatch(
                $verification->id,
                $tempPath,
                $fileKey,
                $type,
                $endpoint,
                $folder,
                $file->getClientOriginalName()
            );

            return $this->successResponse($verification->toArray(), 'تم استلام الملف بنجاح وبدء إعادة المعالجة.', 202);

        } catch (\Exception $e) {
            Log::error("Media Verification Error ($type): " . $e->getMessage());
            return $this->errorResponse('حدث خطأ أثناء فحص الملف', 500);
        } finally {
            $lock->release(); 
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