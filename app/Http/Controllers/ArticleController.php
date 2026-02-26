<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;
use App\Traits\ApiResponseTrait;
use App\Traits\UploadImageTrait; // التريت الذي أنشأناه لرفع الملفات

class ArticleController extends Controller
{
    use ApiResponseTrait, UploadImageTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Article::query();

        // 1. البحث بالاسم
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        // 2. البحث بالتصنيف (تعديل اسم العمود ليتوافق مع الموديل)
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $articles = $query->latest()->get();
        return $this->successResponse($articles, 'Success');
    }

    public function getCategories()
    {       
        $categories = \App\Models\Category::all(); 
        
        return $this->successResponse($categories, 'Categories retrieved successfully');
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request ,string $id)
    {
        $article = Article::with(['category', 'verification'])->find($id);

        if (!$article) {
            return $this->errorResponse('المقال غير موجود', 404);
        }

        return $this->successResponse($article, 'Success');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
