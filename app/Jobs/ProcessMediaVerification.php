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
        // 1. جلب الموديل والتأكد من وجوده
        $verification = Verification::find($this->verificationId);
        if (!$verification) return;
    
        // 2. تهيئة المتغيرات في البداية لتجنب خطأ Undefined variable
        $ai_val = 0.0;
        $real_val = 0.0;
    
        // 3. التأكد من وجود الملف المؤقت
        if (!file_exists($this->tempPath)) {
            Log::error("Temp file not found for verification ID: {$this->verificationId}");
            $verification->update([
                'result_status' => 'Error',
                'description_result' => 'فشل المعالجة: لم يتم العثور على الملف على الخادم.'
            ]);
            return;
        }
    
        try {
            // تحديد رابط الموديل بناءً على النوع
            $baseUrl = match($this->type) {
                'image' => config('services.ai_model.url'),
                'audio' => config('services.ai_model.audio_url'),                
                'video' => config('services.ai_model.video_url'), 
                default => config('services.ai_model.url'),
            };
    
            // فتح الملف كـ Stream لتقليل استهلاك الذاكرة
            $fileStream = fopen($this->tempPath, 'r');
    
            // إرسال الطلب للموديل
            $response = Http::timeout(350)->retry(2, 5000)->attach(
                $this->fileKey, 
                $fileStream, 
                $this->originalName
            )->post($baseUrl . $this->endpoint);
    
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
    
            if (!$response->successful()) {
                throw new \Exception("AI model ({$this->type}) failed to respond: " . $response->body());
            }
    
            $aiResult = $response->json();
    
            // معالجة خطأ من الموديل نفسه
            if (isset($aiResult['status']) && $aiResult['status'] === 'Error') {
                $verification->update([
                    'result_status' => 'Error',
                    'description_result' => $aiResult['explanation'] ?? 'فشل سيرفر الموديل في معالجة الملف'
                ]);
                return;
            }
    
            // استخراج النسب بدقة
            preg_match('/([0-9.]+)\s*%/', $aiResult['explanation'] ?? '', $matches);
            $ai_val = isset($matches[1]) ? (float)$matches[1] : (float)($aiResult['ai_percentage'] ?? 0);
            $real_val = 100 - $ai_val;
    
            // رفع الملف لـ Cloudinary
            $upload = Cloudinary::upload($this->tempPath, [
                'folder'        => $this->folder,
                'public_id'     => $this->hash,
                'resource_type' => 'auto',
                'overwrite'     => true
            ]);
    
            // تحديث قاعدة البيانات بالنتيجة والنسب
            $verification->update([
                'input_data'         => $upload->getSecurePath(),
                'result_status'      => $aiResult['status'] ?? 'done', // افتراض نجاح إذا لم يحدد الموديل
                'description_result' => $aiResult['explanation'] ?? 'Analysis complete',
                'ai_percentage'      => $ai_val,
                'real_percentage'    => $real_val,
            ]);
    
            // بث الحدث للـ Flutter
            event(new \App\Events\MediaVerificationCompleted($verification, $ai_val, $real_val));
            
        } catch (\Exception $e) {
            Log::error("Queue Job Media Verification Error ({$this->type}): " . $e->getMessage());
            
            // تحديث الحالة لـ Error في حال فشل أي شيء
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