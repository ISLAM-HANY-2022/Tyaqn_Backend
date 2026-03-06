<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BookmarksController;

/*===================  AUTH (Public)  =====================*/
Route::post('/register', [AuthController::class, 'register']);//✅
Route::post('/register/verify', [AuthController::class, 'verifyEmailOtp']);
Route::post('/register/resendOtp', [AuthController::class, 'resendRegistrationOtp']);
Route::post('/login', [AuthController::class, 'login']);//✅

// عمليات استعادة كلمة المرور
Route::post('/password/forgot', [PasswordController::class, 'sendResetCode']);//✅
Route::post('/password/verify', [PasswordController::class, 'verifyResetCode']);//✅
Route::post('/password/reset', [PasswordController::class, 'resetPassword']);//✅

/*===================  PROTECTED ROUTES (Sanctum) =====================*/
Route::middleware('auth:sanctum')->group(function () {    
    
    Route::post('/logout', [AuthController::class, 'logout']);//✅    

    /*--- AI Verification ---*/
    Route::post('/verify/text', [AIController::class, 'verifyText']);
    Route::post('/verify/media', [AIController::class, 'verifyMedia']);//✅
    Route::get('/history', [AIController::class, 'history']);//✅

    /*--- Profile ---*/    
    Route::get('/user/profile', [UserController::class, 'profile']);//✅
    Route::post('/user/profile/update', [UserController::class, 'updateProfile']);//✅ 
    Route::post('/deleteAccount', [UserController::class, 'deleteAccount']);
    /*--- Notifications ---*/
    Route::get('/notifications', [NotificationController::class, 'index']);//✅

    /*===================  CONTENT (auth)  =====================*/
    Route::get('/articles', [ArticleController::class, 'index']);//✅
    Route::get('/articles/{id}', [ArticleController::class, 'show']);//✅
    Route::get('/categories', [ArticleController::class, 'getCategories']);
    Route::get('/quizzes', [QuizController::class, 'index']);//✅
    Route::post('bookmarks/toggle/{articleId}', [BookmarksController::class, 'toggleBookmark']);
    Route::get('bookmarks', [BookmarksController::class, 'myBookmarks']);//✅
});
