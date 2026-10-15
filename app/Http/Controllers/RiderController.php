<?php

namespace App\Http\Controllers;

use App\Models\Rider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RiderController extends Controller
{
    private const VALID_STATUSES = ['available', 'busy', 'offline'];

    /** List riders, newest first. Optional ?status= filter. */
    public function index(Request $request)
    {
        try {
            $riders = Rider::when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->get();

            return response()->json(['status' => 'success', 'data' => $riders], 200);

        } catch (\Throwable $th) {
            Log::error('RiderController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Add a new rider. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'phone'        => 'required|string|max:20',
            'vehicle_type' => 'nullable|string|max:50',
        ]);

        try {
            $rider = Rider::create($validated);
            return response()->json(['status' => 'success', 'data' => $rider], 201);

        } catch (\Throwable $th) {
            Log::error('RiderController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Edit rider info or change status (available / busy / offline). */
    public function update(Request $request, Rider $rider)
    {
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'phone'        => 'sometimes|string|max:20',
            'vehicle_type' => 'nullable|string|max:50',
            'status'       => 'sometimes|in:' . implode(',', self::VALID_STATUSES),
        ]);

        try {
            $rider->update($validated);
            return response()->json(['status' => 'success', 'data' => $rider], 200);

        } catch (\Throwable $th) {
            Log::error('RiderController@update: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Remove a rider. */
    public function destroy(Rider $rider)
    {
        try {
            $rider->delete();
            return response()->json(['status' => 'success', 'message' => 'Rider removed'], 200);

        } catch (\Throwable $th) {
            Log::error('RiderController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}