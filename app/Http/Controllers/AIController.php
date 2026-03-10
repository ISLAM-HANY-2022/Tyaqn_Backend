<?php

namespace App\Http\Controllers;

use App\Models\Verification;
use App\Traits\ApiResponseTrait;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AIController extends Controller
{
    use ApiResponseTrait;

    /*================ TEXT =================*/
    public function verifyText(Request $request)
    {
        $request->validate([
            'text_input' => 'required|string',
            'title' => 'nullable|string'
        ]);

        try {

            $baseUrl = config('services.ai_model.url');

            $response = Http::post($baseUrl.'/predict-text', [
                'text' => $request->text_input
            ]);

            if (!$response->successful()) {
                return $this->errorResponse('Text model failed',500);
            }

            $aiResult = $response->json();

            $verification = Verification::create([
                'user_id'=>auth()->id(),
                'title'=>$request->title ?? 'Text Check',
                'input_data'=>$request->text_input,
                'type'=>'text',
                'result_status'=>$aiResult['status'],
                'description_result'=>$aiResult['explanation']
            ]);

            return $this->successResponse($verification,'Text analyzed successfully');

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(),500);
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

        // البحث عن الملف بالبصمة (Hash)
        $existing = Verification::where('file_hash', $hash)->first();

        if ($existing) {
            // التحقق هل الرابط لسه شغال على كلاودناري؟
            $fileHeaders = @get_headers($existing->input_data);
            if ($fileHeaders && strpos($fileHeaders[0], '200')) {
                return $this->successResponse($existing, 'File already analyzed');
            }
            // لو مش موجود (404)، الكود هيكمل ويحدث السجل القديم
        }

        return DB::transaction(function () use ($request, $file, $hash, $type, $endpoint, $folder, $existing) {
            
            $baseUrl = config('services.ai_model.url');

            // إرسال الملف للـ AI
            $response = Http::timeout(150)->attach(
                $fileKey, 
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post($baseUrl . $endpoint);

            if (!$response->successful()) {
                return $this->errorResponse('AI model failed', 500);
            }

            $aiResult = $response->json();

            // الرفع لـ Cloudinary
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

            // لو السجل موجود (بس الرابط كان مكسور) بنحدثه، لو مش موجود بننشئ جديد
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