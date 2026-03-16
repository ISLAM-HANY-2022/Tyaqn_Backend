<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use App\Models\Verification;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    use ApiResponseTrait;

    /**
     * جلب جميع التقارير (لكل المستخدمين - الصفحة العامة)
     */
    public function index()
    {
        $reports = Report::approved() // استخدمنا الـ Scope هنا
            ->with(['user:id,name,profile_image', 'verification'])
            ->latest()
            ->paginate(10);

        return $this->successResponse($reports, "Community reports viewed successfully");
    }

    /**
     * جلب التقارير الخاصة بالمستخدم الحالي فقط (صفحة تقاريري)
     */
    public function myReports(Request $request)
    {
        $user = $request->user();
        
        $reports = Report::where('user_id', $user->id)
            ->with('verification')
            ->latest()
            ->paginate(10);

        return $this->successResponse($reports, "Your reports retrieved successfully");
    }

    /**
     * إنشاء تقرير جديد مبني على تحقق سابق
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'verification_id' => 'required|exists:verifications,id',
            'title'           => 'required|string|max:255',
            'description'     => 'required|string',
            'source_link'     => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        // التأكد أن التحقق يخص المستخدم
        $verification = Verification::where('id', $request->verification_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$verification) {
            return $this->errorResponse('Unauthorized or verification not found', 403);
        }

        $report = Report::create([
            'user_id'         => $request->user()->id,
            'verification_id' => $request->verification_id,
            'title'           => $request->title,
            'description'     => $request->description,
            'source_link'     => $request->source_link,
            'status'          => 'pending', // الحالة الافتراضية
        ]);
       
        return $this->successResponse($report, 'Report submitted for review', 201);
    }

    /**
     * عرض تفاصيل تقرير معين (لما المستخدم يضغط عليه)
     */
    public function show($id)
    {
        $report = Report::with(['user:id,name,profile_image', 'verification'])->find($id);

        if (!$report) {
            return $this->errorResponse("Report not found", 404);
        }

        return $this->successResponse($report, "Report details retrieved successfully");
    }

    /**
     * عرض تفاصيل حالة التقرير (متطابق مع شاشة Report Status في Figma)
     */
    public function showReportStatus(Request $request, $id)
    {
        $report = Report::where('id', $id)
        ->where('user_id', $request->user()->id)
        ->with(['user:id,name', 'verification'])
        ->first();

        if (!$report) {
        return $this->errorResponse("Report not found", 404);
         }

    // تجهيز البيانات لتناسب شاشة "Report Status" في الفيجما
        $formattedData = [
            'report_id'       => '#' . $report->id,
            'submitted_by'    => $report->user->name,
            'date_submitted'  => $report->created_at->format('Y-m-d h:i A'),
            'content_type'    => $report->verification ? ucfirst($report->verification->type) : 'N/A',
            'content_link'    => $report->source_link ?? ($report->verification ? $report->verification->input_data : 'N/A'),
            'status'          => $report->status, // pending, in_review, approved, rejected
            'admin_feedback'  => $report->admin_feedback ?? 'No feedback yet.',
        ];

        return $this->successResponse($formattedData, "Report status details");
    }
   

    /**
     * حذف التقرير (Delete My Report)
     */
    public function destroy(Request $request, $id)
    {
        $report = Report::where('id', $id)
        ->where('user_id', $request->user()->id)
        ->first();

        if (!$report) {
            return $this->errorResponse("Report not found", 404);
        }

        $report->delete();
        return $this->successResponse(null, "Done! The item was removed successfully");
    }
    
}