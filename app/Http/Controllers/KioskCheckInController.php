<?php

namespace App\Http\Controllers;

use App\Services\KioskCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskCheckInController extends Controller
{
    public function scan(Request $request, KioskCheckInService $kiosk): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($kiosk->scan($validated['token'])->toArray());
    }
}
