<?php

namespace App\Traits;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

trait UploadImageTrait
{
    /**
     * رفع صورة إلى كلاودناري داخل مجلد محدد
     */
    public function uploadToCloudinary($file, $folderName)
    {
        // رفع الملف وتحديد المجلد
        $upload = Cloudinary::upload($file->getRealPath(), [
            'folder' => 'Tyaqn/' . $folderName
        ]);

        return $upload->getSecurePath(); // إرجاع رابط الصورة الآمن (https)
    }

    /**
     * حذف صورة من كلاودناري باستخدام الرابط الخاص بها
     */
    public function deleteFromCloudinary($imageUrl)
    {
        if (!$imageUrl) return;
    
        try {
            // 1. استخراج ما بعد /upload/
            $pathAfterUpload = explode('/upload/', $imageUrl)[1] ?? null;
            if (!$pathAfterUpload) return;
    
            $segments = explode('/', $pathAfterUpload);
    
            // 2. إزالة الجزء الخاص بالنسخة (v123456)
            if (isset($segments[0]) && preg_match('/v\d+/', $segments[0])) {
                array_shift($segments);
            }
    
            // 3. إعادة دمج المسار (المجلدات + اسم الملف)
            $fullPathWithExtension = implode('/', $segments);
    
            // 4. فك تشفير الرموز (%20 للمسافات)
            $decodedPath = urldecode($fullPathWithExtension);
    
            // 5. إزالة الامتداد فقط مع الحفاظ على مسار المجلدات كامل
            // بنشيل آخر 4 أو 5 حروف اللي هما (.jpg أو .png)
            $publicId = preg_replace('/\.[^.]+$/', '', $decodedPath);
    
            // 6. الحذف من كلاودناري
            Cloudinary::destroy($publicId);
    
        } catch (\Exception $e) {
            \Log::error("Cloudinary Delete Error: " . $e->getMessage());
        }
    }
}