<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Quiz; 
use App\Traits\ApiResponseTrait; // <--- هنا الاستدعاء مع المكتبات فوق كما طلبت

class QuizController extends Controller
{
    // لابد من كتابتها هنا أيضاً لكي يفهم الكلاس أنه سيمتلك دوال الـ Trait
    use ApiResponseTrait; 

    public function index()
    {
        // بناءً على جدول الاختبارات رقم 6 في ملفك 
        // الحقول: id, question, option_a, option_b, correct_answer 
        $quizzes = Quiz::latest()->paginate(10);

        return $this->successResponse($quizzes, 'Quizzes retrieved successfully');
    }
}