<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\AbstractPaginator;

class ApiResponse
{
    /**
     * Return a standardized successful JSON response with dual-compatibility.
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        $defaultMeta = [
            'timestamp' => now()->toIso8601String(),
            'version' => 'v1',
        ];

        // 1. Handle Paginators
        if ($data instanceof AbstractPaginator) {
            return self::paginated($data, $message, $status, $meta);
        }

        // 2. Prevent redundant ['data' => $item] double nesting
        if (is_array($data) && count($data) === 1 && array_key_exists('data', $data)) {
            $data = $data['data'];
        }

        $dataArray = $data instanceof Arrayable ? $data->toArray() : $data;

        $payload = [
            'success' => true,
            'message' => $message ?? 'Success',
            'data' => $dataArray,
            'meta' => array_merge($defaultMeta, $meta),
        ];

        // 3. Dual-compatibility: merge associative model attributes into top level
        if (is_array($dataArray) && !array_is_list($dataArray)) {
            foreach ($dataArray as $k => $v) {
                if (!in_array($k, ['success', 'message', 'data', 'meta'], true)) {
                    $payload[$k] = $v;
                }
            }
        }

        return response()->json($payload, $status);
    }

    /**
     * Return a standardized paginated response preserving root pagination keys.
     */
    public static function paginated(AbstractPaginator $paginator, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        $paginatorArray = $paginator->toArray();
        $paginationMeta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        $mergedMeta = array_merge([
            'timestamp' => now()->toIso8601String(),
            'version' => 'v1',
            'pagination' => $paginationMeta,
        ], $meta);

        // Preserve root pagination keys for tests expecting current_page, total, etc.
        $payload = array_merge($paginatorArray, [
            'success' => true,
            'message' => $message ?? 'Success',
            'data' => $paginatorArray['data'] ?? $paginator->items(),
            'meta' => $mergedMeta,
        ]);

        return response()->json($payload, $status);
    }

    /**
     * Return a standardized error response.
     */
    public static function error(string $message, int $status = 400, mixed $errors = null, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $status,
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
                'version' => 'v1',
            ], $meta),
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Return a standardized 201 Created response.
     */
    public static function created(mixed $data = null, ?string $message = 'Resource created successfully.', array $meta = []): JsonResponse
    {
        return self::success($data, $message, 201, $meta);
    }

    /**
     * Return a simple status message response.
     */
    public static function message(string $message, int $status = 200, array $meta = []): JsonResponse
    {
        return self::success(null, $message, $status, $meta);
    }

    /**
     * Return a 204 No Content response.
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
