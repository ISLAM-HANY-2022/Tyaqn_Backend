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
use Illuminate\Support\Facades\Auth;

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
                    // 1. التحقق: هل مر أكثر من 10 دقائق على السجل؟
                    if ($existing->updated_at->diffInMinutes(now()) > 10) {
                        // نعتبره "سجلاً تالفاً" (stale)، ونقوم بتغيير حالته لـ Error ليسمح الكود بإعادة إرسال الـ Job
                        $existing->update(['result_status' => 'Error']);
                        // سنسمح للكود بالاستمرار ليقوم بإنشاء Job جديد في الـ else التالية
                    } else {
                        // لا يزال في فترة الانتظار (أقل من 10 دقائق)، نمنع التكرار
                        return $this->errorResponse('هذا الملف قيد المعالجة حالياً، يرجى الانتظار...', 202);
                    }
                }
              
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
              $file->getClientOriginalName(),
              $hash
          );

          // التعديل هنا: حقن النسب المبدئية للرد
          $responseData = $verification->toArray();
          $responseData['ai_percentage'] = 0;
          $responseData['real_percentage'] = 0;

          return $this->successResponse($responseData, 'تم استلام ملف الفيديو بنجاح، وجاري الفحص.', 202);

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
                    // 1. التحقق: هل مر أكثر من 10 دقائق على السجل؟
                    if ($existing->updated_at->diffInMinutes(now()) > 10) {
                        // نعتبره "سجلاً تالفاً" (stale)، ونقوم بتغيير حالته لـ Error ليسمح الكود بإعادة إرسال الـ Job
                        $existing->update(['result_status' => 'Error']);
                        // سيقوم الكود بالاستمرار في الـ Scope الحالي وتحديث السجل وبدء Job جديد
                    } else {
                        // لا يزال في فترة الانتظار (أقل من 10 دقائق)، نجهز البيانات بـ 0 ونمنع التكرار
                        $pendingData = $existing->toArray();
                        $pendingData['ai_percentage'] = 0;
                        $pendingData['real_percentage'] = 0;
                        return $this->successResponse($pendingData, 'هذا الملف قيد المعالجة حالياً، يرجى الانتظار...', 202);
                    }
                }

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
              $file->getClientOriginalName(),
              $hash
          );

          // التعديل هنا: حقن النسب المبدئية للرد
          $responseData = $verification->toArray();
          $responseData['ai_percentage'] = 0;
          $responseData['real_percentage'] = 0;

          return $this->successResponse($responseData, 'تم استلام الملف بنجاح وبدء إعادة المعالجة.', 202);

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
    public function show($id)
    {
        // 1. جلب الفحص والتأكد أنه يخص المستخدم الحالي لمنع التلاعب
       // بدلاً من الاستعلام ثم التحقق، يمكنك دمجهم:
        $verification = Verification::where('id', $id)
        ->where('user_id', Auth::id())
        ->firstOrFail(); // سيقوم لارافل تلقائياً بإرجاع 404 إذا لم يجد السجل
        // 2. إذا لم يتم العثور على الفحص
        if (!$verification) {
            return response()->json([
                'status' => false,
                'message' => 'Verification record not found or unauthorized.'
            ], 404);
        }

        // 3. إرجاع البيانات بنفس الـ Structure اللي بتستعمله في الـ Postman
        return response()->json([
            'status' => true,
            'message' => 'Verification details retrieved successfully.',
            'data' => [
                'id' => $verification->id,
                'file_hash' => $verification->file_hash,
                'user_id' => $verification->user_id,
                'title' => $verification->title,
                'input_data' => $verification->input_data, // رابط الـ Cloudinary
                'type' => $verification->type,             // video, image, etc.
                'result_status' => $verification->result_status, // Real or Fake
                'description_result' => $verification->description_result,
                'ai_percentage' => (float) $verification->ai_percentage,
                'real_percentage' => (float) $verification->real_percentage,
                'created_at' => $verification->created_at,
                'updated_at' => $verification->updated_at,
            ]
        ], 200);
    }
}