<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QrisDynamicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class JokiQrisController extends Controller
{
    public function __construct(private readonly QrisDynamicService $qris) {}

    public function validateTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'string', 'max:4096'],
        ]);

        try {
            return response()->json(['data' => $this->qris->inspectStatic($data['payload'])]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
