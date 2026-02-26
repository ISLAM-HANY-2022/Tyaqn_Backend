<?php
namespace App\Traits;

trait ApiResponseTrait
{
    // رد النجاح
    public function successResponse($data = null, $message = 'Success', $code = 200)
    {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => $data
        ], $code);
    }

    // رد الخطأ
    public function errorResponse($message = 'Error', $errors = null, $code = 400)
    {
        return response()->json([
            'status'  => false,
            'message' => $message,
            'errors'  => $errors
        ], $code);
    }
}
