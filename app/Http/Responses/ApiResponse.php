<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function success(?array $data, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>|null  $fields
     */
    public static function error(string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $status);
    }
}
