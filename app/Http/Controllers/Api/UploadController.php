<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $file = $validated['image'];
        $directory = public_path('uploads/botakstore');
        $filename = Str::uuid().'.'.$file->guessExtension();

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file->move($directory, $filename);
        $path = '/uploads/botakstore/'.$filename;

        return response()->json([
            'url' => url($path),
            'path' => $path,
        ], 201);
    }
}
