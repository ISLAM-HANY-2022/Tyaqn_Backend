<?php

namespace App\Jobs;

use App\Models\Verification;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessMediaVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 450; // زيادة الوقت لضمان معالجة الفيديوهات الكبيرة في الخلفية مبروك

    protected $verificationId;
    protected $tempPath;
    protected $fileKey;
    protected $type;
    protected $endpoint;
    protected $folder;
    protected $originalName;
    protected $hash;

    public function __construct($verificationId, $tempPath, $fileKey, $type, $endpoint, $folder, $originalName, $hash)
    {
        $this->verificationId = $verificationId;
        $this->tempPath = $tempPath;
        $this->fileKey = $fileKey;
        $this->type = $type;
        $this->endpoint = $endpoint;
        $this->folder = $folder;
        $this->originalName = $originalName;
        $this->hash = $hash;
    }

    public function handle()
    {
        $verification = Verification::find($this->verificationId);
        if (!$verification) return;

        if (!file_exists($this->tempPath)) {
            Log::error("Temp file not found for verification ID: {$this->verificationId}");
            $verification->update([
                'result_status' => 'Error',
                'description_result' => 'فشل المعالجة: لم يتم العثور على الملف على الخادم.'
            ]);
            return;
        }

        try {
            $baseUrl = match($this->type) {
                'image' => config('services.ai_model.url'),
                'audio' => config('services.ai_model.audio_url'),                
                'video' => config('services.ai_model.video_url'), 
                default => config('services.ai_model.url'),
            };

            // الحل السريع والمستقر: فتح الملف كـ Stream (رابط مباشر للهارد) بدل قراءته بالكامل في الذاكرة
            $fileStream = fopen($this->tempPath, 'r');

            // 1. إرسال تدفقي (Streaming) فوراً لموديل الـ AI لمنع الـ Memory Crash
            $response = Http::timeout(350)->retry(2, 5000)->attach(
                $this->fileKey, 
                $fileStream, 
                $this->originalName
            )->post($baseUrl . $this->endpoint);

            // غلق الـ Stream بأمان بعد الإرسال
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }

            if (!$response->successful()) {
                throw new \Exception("AI model ({$this->type}) failed to respond: " . $response->body());
            }

            $aiResult = $response->json();

            if (isset($aiResult['status']) && $aiResult['status'] === 'Error') {
                $verification->update([
                    'result_status' => 'Error',
                    'description_result' => $aiResult['explanation'] ?? 'فشل سيرفر الموديل في معالجة الملف'
                ]);
                return;
            }

            // 2. رفع الملف على كلوديناري لحفظ الرابط السحابي للمستقبل
            $upload = Cloudinary::upload($this->tempPath, [
                'folder'        => $this->folder,
                'public_id'     => $this->hash, // **هنا السحر: استخدام الـ Hash كاسم ثابت**
                'resource_type' => 'auto',
                'overwrite'     => true         // **هنا التأكيد: تحديث القديم بدلاً من خلق نسخة جديدة**
            ]);

            // 3. تحديث قاعدة البيانات بالنتيجة والرابط المستقر
            $verification->update([
                'input_data'         => $upload->getSecurePath(),
                'result_status'      => $aiResult['status'] ?? 'unknown',
                'description_result' => $aiResult['explanation'] ?? 'Analysis complete'
            ]);

            // 4. استخراج النسب بدقة لإرسالها للـ Event
            $ai_val = 0;
            preg_match('/([0-9.]+)\s*%/', $verification->description_result, $matches);
            if (isset($matches[1])) {
                $ai_val = (float)$matches[1];
            } elseif (isset($aiResult['ai_percentage'])) {
                $ai_val = (float)$aiResult['ai_percentage'];
            }
            $real_val = 100 - $ai_val;

            // بث الحدث اللحظي للـ Flutter
            event(new \App\Events\MediaVerificationCompleted($verification, $ai_val, $real_val));
            
        } catch (\Exception $e) {
            Log::error("Queue Job Media Verification Error ({$this->type}): " . $e->getMessage());
            $verification->update([
                'result_status' => 'Error',
                'description_result' => 'حدث خطأ غير متوقع أثناء معالجة الملف: ' . $e->getMessage()
            ]);
        } finally {
            $this->cleanup();
        }
    }

    protected function cleanup()
    {
        if (file_exists($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }
}