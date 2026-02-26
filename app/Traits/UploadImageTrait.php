<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait UploadImageTrait
{
    /**
     * وظيفة هذا الكود: استلام الملف، تسميته، وتخزينه في السيرفر
     */
    public function uploadFile($file, $folderName)
    {
        // 1. حساب الهاش للملف قبل نقله (مهم جداً للمقارنة)
        $fileHash = md5_file($file->getRealPath());

        // 2. توليد اسم فريد للملف
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '_' . time() . '.' . $extension;

        // 3. تحديد المسار
        $destinationPath = public_path('uploads/' . $folderName);

        // 4. نقل الملف فعلياً للمجلد
        $file->move($destinationPath, $fileName);

        // 5. إرجاع مصفوفة (Array) تحتوي على المسار والهاش
        return [
            'path' => 'uploads/' . $folderName . '/' . $fileName,
            'hash' => $fileHash
        ];
    }
}