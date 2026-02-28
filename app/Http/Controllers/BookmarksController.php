<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponseTrait;
use App\Models\Article;

class BookmarksController extends Controller
{
    use ApiResponseTrait;

    // 1. إضافة أو حذف مقال من المفضلة (Toggle)
    public function toggleBookmark($articleId)
    {
        $user = auth()->user();
        $article = Article::find($articleId);

        if (!$article) {
            return $this->errorResponse('المقال غير موجود', 404);
        }

        // دالة toggle تقوم بالإضافة إذا لم يكن موجوداً، وبالحذف إذا كان موجوداً
        $user->bookmarks()->toggle($articleId);

        return $this->successResponse(null, 'Bookmarks updated successfully');
    }

    // 2. عرض كافة المقالات المحفوظة للمستخدم الحالي
    public function myBookmarks()
    {
        $bookmarks = auth()->user()->bookmarks()->with('category')->latest()->get();
        return $this->successResponse($bookmarks, 'تم جلب المقالات المحفوظة');
    }
}