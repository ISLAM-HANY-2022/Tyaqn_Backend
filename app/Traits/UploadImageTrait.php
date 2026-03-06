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

        // استخراج الـ Public ID من الرابط
        // الرابط بيكون شكله: https://res.cloudinary.com/demo/image/upload/v1234/Tyaqn/Profiles/xyz.jpg
        // إحنا محتاجين: Tyaqn/Profiles/xyz
        $path = parse_url($imageUrl, PHP_URL_PATH);
        $segments = explode('/', $path);
        
        // الحصول على الجزء بعد 'upload/' وتجاهل الـ version (v1234)
        $publicIdWithExtension = implode('/', array_slice($segments, 5)); 
        
        // إزالة الامتداد (مثل .jpg)
        $publicId = pathinfo($publicIdWithExtension, PATHINFO_FILENAME);
        
        // دالة الحذف من المكتبة (بنضيف اسم المجلد قبل الـ ID لو مش موجود فيه)
        // لكن بما إننا خزناه بالمجلد، الـ Public ID المستخرج هيحتوي عليه.
        Cloudinary::destroy($publicId);
    }
}