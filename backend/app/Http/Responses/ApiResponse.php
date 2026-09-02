<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Standard API envelope:
 *   success: { success: true,  message, data, meta }
 *   error:   { success: false, message, error_code, data }
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
            'meta' => (object) $meta,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created', array $meta = []): JsonResponse
    {
        return self::success($data, $message, 201, $meta);
    }

    public static function error(string $message, string $errorCode = 'ERROR', int $status = 400, mixed $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'data' => $data ?? (object) [],
        ], $status);
    }
}
