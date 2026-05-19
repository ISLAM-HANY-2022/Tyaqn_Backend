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

    // تحديد وقت أقصى لتنفيذ الوظيفة (5 دقائق للفيديوهات الكبيرة) لضمان عدم توقف الكيوجوب
    public $timeout = 300;

    protected $verificationId;
    protected $tempPath;
    protected $fileKey;
    protected $type;
    protected $endpoint;
    protected $folder;
    protected $originalName;

    public function __construct($verificationId, $tempPath, $fileKey, $type, $endpoint, $folder, $originalName)
    {
        $this->verificationId = $verificationId;
        $this->tempPath = $tempPath;
        $this->fileKey = $fileKey;
        $this->type = $type;
        $this->endpoint = $endpoint;
        $this->folder = $folder;
        $this->originalName = $originalName;
    }

    public function handle()
    {
        $verification = Verification::find($this->verificationId);
        if (!$verification) {
            return;
        }

        // التأكد من أن الملف المؤقت موجود على السيرفر ولم يُحذف
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

            // 1. استدعاء موديل الـ AI من هجينج فيس مع مهلة زمنية مريحة
            $response = Http::timeout(250)->retry(2, 4000)->attach(
                $this->fileKey, 
                file_get_contents($this->tempPath),
                $this->originalName
            )->post($baseUrl . $this->endpoint);

            if (!$response->successful()) {
                throw new \Exception("AI model ({$this->type}) failed to respond: " . $response->body());
            }

            $aiResult = $response->json();

            // معالجة حالة إرجاع خطأ من الـ AI Space نفسه
            if (isset($aiResult['status']) && $aiResult['status'] === 'Error') {
                $verification->update([
                    'result_status' => 'Error',
                    'description_result' => $aiResult['explanation'] ?? 'فشل سيرفر الموديل في معالجة الملف'
                ]);
                $this->cleanup();
                return;
            }

            // 2. رفع الملف على كلوديناري لحفظ الرابط الآمن
            $upload = Cloudinary::upload($this->tempPath, [
                'folder' => $this->folder,
                'resource_type' => 'auto'
            ]);

            // 3. تحديث السجل الفعلي بالنتائج النهائية وتغيير الحالة من pending إلى الحالة الجديدة
            $verification->update([
                'input_data'         => $upload->getSecurePath(),
                'result_status'      => $aiResult['status'] ?? 'unknown',
                'description_result' => $aiResult['explanation'] ?? 'Analysis complete'
            ]);

            // [ملاحظة مستقبلية للـ Real-time اللحظي]: 
            // هنا المكان الأنسب لبث الـ Event لتنبيه مبرمج الفلاتر فجأة عبر ويب سوكيت (Reverb/Pusher)
            $ai_val = $aiResult['ai_percentage'] ?? 0;
            $real_val = $aiResult['real_percentage'] ?? (100 - $ai_val);

            // الآن استدع الـ Event مع المتغيرات المعرفة
            event(new \App\Events\MediaVerificationCompleted(
                $verification, 
                $ai_val, 
                $real_val
            ));
            
        } catch (\Exception $e) {
            Log::error("Queue Job Media Verification Error ({$this->type}): " . $e->getMessage());
            $verification->update([
                'result_status' => 'Error',
                'description_result' => 'حدث خطأ غير متوقع أثناء فحص الملف في الخلفية.'
            ]);
        } finally {
            $this->cleanup();
        }
    }

    // دالة داخلية لتنظيف السيرفر وحذف الملف المؤقت لعدم استهلاك مساحة القرص
    protected function cleanup()
    {
        if (file_exists($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }
}