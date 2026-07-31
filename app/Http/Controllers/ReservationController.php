<?php

namespace App\Http\Controllers;

use App\Events\ReservationStatusChanged;
use App\Mail\ReservationStatusMail;
use App\Models\Notification;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class ReservationController extends Controller
{
    private const DEFAULT_DURATION_MINUTES = 120;

    // ─── CUSTOMER ENDPOINTS ──────────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $query = Reservation::with(['user:id,name', 'table:id,name', 'confirmedBy:id,name'])
                ->when(
                    $request->user()->role !== 'admin' && $request->user()->role !== 'staff',
                    fn($q) => $q->where('user_id', $request->user()->id)
                )
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $query], 200);

        } catch (\Throwable $th) {
            Log::error('ReservationController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $reservation = Reservation::with(['user:id,name', 'table', 'confirmedBy:id,name'])
                ->findOrFail($id);

            if (
                $request->user()->role !== 'admin' &&
                $request->user()->role !== 'staff' &&
                $reservation->user_id !== $request->user()->id
            ) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            }

            return response()->json(['status' => 'success', 'data' => $reservation], 200);

        } catch (\Throwable $th) {
            Log::error('ReservationController@show: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_id'    => 'required|exists:tables,id',
            'guest_count' => 'required|integer|min:1',
            'reserved_at' => 'required|date|after:now',
            'notes'       => 'nullable|string',
        ]);

        try {
            $reservation = DB::transaction(function () use ($validated, $request) {
                if ($this->hasConflict($validated['table_id'], $validated['reserved_at'])) {
                    throw new \RuntimeException('TABLE_CONFLICT');
                }

                return Reservation::create([
                    'user_id'     => $request->user()->id,
                    'table_id'    => $validated['table_id'],
                    'guest_count' => $validated['guest_count'],
                    'reserved_at' => $validated['reserved_at'],
                    'notes'       => $validated['notes'] ?? null,
                    'status'      => Reservation::STATUS_PENDING,
                ]);
            });

            DB::afterCommit(function () use ($reservation) {
                try {
                    event(new ReservationStatusChanged(
                        $reservation->fresh(['user', 'table']),
                        'created'
                    ));
                } catch (\Throwable $broadcastError) {
                    Log::warning('ReservationController@store broadcast failed: ' . $broadcastError->getMessage());
                }
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Reservation created successfully.',
                'data'    => $reservation->load(['user:id,name', 'table']),
            ], 201);

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'TABLE_CONFLICT') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This table is already booked around that time. Please choose another time or table.',
                ], 409);
            }
            throw $e;

        } catch (\Throwable $th) {
            Log::error('ReservationController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'table_id'    => 'sometimes|exists:tables,id',
            'guest_count' => 'sometimes|integer|min:1',
            'reserved_at' => 'sometimes|date|after:now',
            'notes'       => 'nullable|string',
        ]);

        try {
            $reservation = Reservation::findOrFail($id);

            if ($reservation->user_id !== $request->user()->id) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            }

            if ($reservation->status !== Reservation::STATUS_PENDING) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Only pending reservations can be edited.',
                ], 422);
            }

            $newTableId    = $validated['table_id'] ?? $reservation->table_id;
            $newReservedAt = $validated['reserved_at'] ?? $reservation->reserved_at;

            if (
                (isset($validated['table_id']) || isset($validated['reserved_at'])) &&
                $this->hasConflict($newTableId, $newReservedAt, excludeId: $reservation->id)
            ) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This table is already booked around that time. Please choose another time or table.',
                ], 409);
            }

            $reservation->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Reservation updated successfully.',
                'data'    => $reservation->fresh(['user:id,name', 'table']),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReservationController@update: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/reservations/{id}/cancel
     *
     * FIX #1: previously this method NEVER sent a status email to the
     * customer — it only broadcast the event. Now it also calls
     * sendStatusEmail() with status 'cancelled', matching the pattern used
     * in confirm()/reject()/complete().
     */
    public function cancel(Request $request, $id)
    {
        try {
            $reservation = Reservation::findOrFail($id);

            if (
                $reservation->user_id !== $request->user()->id &&
                $request->user()->role !== 'admin'
            ) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            }

            if (in_array($reservation->status, [
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_CANCELLED,
            ])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This reservation cannot be cancelled. Current status: ' . $reservation->status,
                ], 422);
            }

            DB::transaction(function () use ($reservation) {
                $reservation->update(['status' => Reservation::STATUS_CANCELLED]);
            });

            DB::afterCommit(function () use ($reservation) {
                try {
                    event(new ReservationStatusChanged($reservation->fresh(['user', 'table']), 'cancelled'));
                } catch (\Throwable $broadcastError) {
                    Log::warning('ReservationController@cancel broadcast failed: ' . $broadcastError->getMessage());
                }

                // FIX #1: send the cancellation email (was missing entirely before).
                $this->sendStatusEmail($reservation, 'cancelled', null);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Reservation cancelled.',
                'data'    => $reservation->fresh(),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReservationController@cancel: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function stats(Request $request)
    {
        try {
            $today = now()->toDateString();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'total'     => Reservation::count(),
                    'pending'   => Reservation::where('status', Reservation::STATUS_PENDING)->count(),
                    'confirmed' => Reservation::where('status', Reservation::STATUS_CONFIRMED)->count(),
                    'today'     => Reservation::whereDate('reserved_at', $today)
                        ->whereIn('status', [Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED])
                        ->count(),
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReservationController@stats: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── ADMIN ENDPOINTS ─────────────────────────────────────────────────────

    public function confirm(Request $request, $id)
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        try {
            $reservation = DB::transaction(function () use ($id, $request) {
                $reservation = Reservation::with('user')
                    ->lockForUpdate()
                    ->findOrFail($id);

                if ($reservation->status !== Reservation::STATUS_PENDING) {
                    throw new \RuntimeException('BAD_STATUS:' . $reservation->status);
                }

                if ($this->hasConflict(
                    $reservation->table_id,
                    $reservation->reserved_at,
                    excludeId: $reservation->id,
                    onlyConfirmed: true
                )) {
                    throw new \RuntimeException('TABLE_CONFLICT');
                }

                $reservation->update([
                    'status'       => Reservation::STATUS_CONFIRMED,
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                ]);

                return $reservation;
            });

            $customerMessage = $validated['message'] ?? null;

            DB::afterCommit(function () use ($reservation, $customerMessage) {
                try {
                    if ($reservation->user_id) {
                        Notification::create([
                            'user_id'        => $reservation->user_id,
                            'title'          => 'Reservation Confirmed',
                            'message'        => "Your reservation for {$reservation->guest_count} guest(s) on " .
                                $reservation->reserved_at->format('M j, Y H:i') . ' has been confirmed.',
                            'type'           => Notification::TYPE_RESERVATION,
                            'reference_id'   => $reservation->id,
                            'reference_type' => 'reservation',
                        ]);
                    }

                    event(new ReservationStatusChanged(
                        $reservation->fresh(['user', 'table', 'confirmedBy']),
                        'confirmed'
                    ));
                } catch (\Throwable $broadcastError) {
                    Log::warning('ReservationController@confirm broadcast/notification failed: ' . $broadcastError->getMessage());
                }

                $this->sendStatusEmail($reservation, 'confirmed', $customerMessage);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Reservation confirmed.',
                'data'    => $reservation->fresh(['user:id,name', 'table', 'confirmedBy:id,name']),
            ], 200);

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'TABLE_CONFLICT') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Another reservation for this table already overlaps this time slot.',
                ], 409);
            }
            if (str_starts_with($e->getMessage(), 'BAD_STATUS:')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Only pending reservations can be confirmed. Current status: ' . substr($e->getMessage(), 11),
                ], 422);
            }
            throw $e;

        } catch (\Throwable $th) {
            Log::error('ReservationController@confirm: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'notes'   => 'nullable|string',
            'message' => 'nullable|string|max:1000',
        ]);

        try {
            $reservation = DB::transaction(function () use ($id, $request) {
                $reservation = Reservation::with('user')->lockForUpdate()->findOrFail($id);

                if ($reservation->status !== Reservation::STATUS_PENDING) {
                    throw new \RuntimeException('BAD_STATUS:' . $reservation->status);
                }

                $reservation->update([
                    'status'       => Reservation::STATUS_REJECTED,
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                    'notes'        => $request->notes ?? $reservation->notes,
                ]);

                return $reservation;
            });

            $customerMessage = $validated['message'] ?? null;

            DB::afterCommit(function () use ($reservation, $customerMessage) {
                try {
                    if ($reservation->user_id) {
                        Notification::create([
                            'user_id'        => $reservation->user_id,
                            'title'          => 'Reservation Not Available',
                            'message'        => "Unfortunately your reservation for {$reservation->guest_count} guest(s) on " .
                                $reservation->reserved_at->format('M j, Y H:i') . ' could not be accommodated.',
                            'type'           => Notification::TYPE_RESERVATION,
                            'reference_id'   => $reservation->id,
                            'reference_type' => 'reservation',
                        ]);
                    }

                    event(new ReservationStatusChanged(
                        $reservation->fresh(['user', 'table', 'confirmedBy']),
                        'rejected'
                    ));
                } catch (\Throwable $broadcastError) {
                    Log::warning('ReservationController@reject broadcast/notification failed: ' . $broadcastError->getMessage());
                }

                $this->sendStatusEmail($reservation, 'rejected', $customerMessage);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Reservation rejected.',
                'data'    => $reservation->fresh(['user:id,name', 'table', 'confirmedBy:id,name']),
            ], 200);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'BAD_STATUS:')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Only pending reservations can be rejected. Current status: ' . substr($e->getMessage(), 11),
                ], 422);
            }
            throw $e;

        } catch (\Throwable $th) {
            Log::error('ReservationController@reject: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function complete(Request $request, $id)
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        try {
            $reservation = DB::transaction(function () use ($id) {
                $reservation = Reservation::with('user')->lockForUpdate()->findOrFail($id);

                if ($reservation->status !== Reservation::STATUS_CONFIRMED) {
                    throw new \RuntimeException('BAD_STATUS:' . $reservation->status);
                }

                $reservation->update(['status' => Reservation::STATUS_COMPLETED]);

                return $reservation;
            });

            $customerMessage = $validated['message'] ?? null;

            DB::afterCommit(function () use ($reservation, $customerMessage) {
                try {
                    event(new ReservationStatusChanged($reservation->fresh(['user', 'table']), 'completed'));
                } catch (\Throwable $broadcastError) {
                    Log::warning('ReservationController@complete broadcast failed: ' . $broadcastError->getMessage());
                }

                $this->sendStatusEmail($reservation, 'completed', $customerMessage);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Reservation marked as completed.',
                'data'    => $reservation->fresh(['user:id,name', 'table']),
            ], 200);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'BAD_STATUS:')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Only confirmed reservations can be completed. Current status: ' . substr($e->getMessage(), 11),
                ], 422);
            }
            throw $e;

        } catch (\Throwable $th) {
            Log::error('ReservationController@complete: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $reservation = Reservation::findOrFail($id);
            $reservation->delete();

            return response()->json(['status' => 'success', 'message' => 'Reservation deleted.'], 200);

        } catch (\Throwable $th) {
            Log::error('ReservationController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── PRIVATE HELPERS ─────────────────────────────────────────────────────

    private function hasConflict($tableId, $reservedAt, ?int $excludeId = null, bool $onlyConfirmed = false): bool
    {
        $start = Carbon::parse($reservedAt);
        $end   = $start->copy()->addMinutes(self::DEFAULT_DURATION_MINUTES);

        $statuses = $onlyConfirmed
            ? [Reservation::STATUS_CONFIRMED]
            : [Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED];

        return Reservation::where('table_id', $tableId)
            ->whereIn('status', $statuses)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->where('reserved_at', '<', $end)
            ->whereRaw(
                "reserved_at + (? * INTERVAL '1 minute') > ?",
                [self::DEFAULT_DURATION_MINUTES, $start]
            )
            ->exists();
    }

    /**
     * FIX #2: switched Mail::to()->queue() to Mail::to()->send().
     *
     * Reason: queue() only inserts a row into the `jobs` table — it requires
     * a running `php artisan queue:work` (or Supervisor/cron) to actually
     * dispatch the email. Since this project has no worker running, every
     * queued reservation email sat in `jobs` (or silently failed into
     * `failed_jobs`) and was never sent. send() delivers synchronously in
     * the same request, exactly like the working ContactController mailer.
     */
    private function sendStatusEmail(Reservation $reservation, string $status, ?string $note): void
    {
        $email = $reservation->user?->email;

        if (!$email) {
            return;
        }

        try {
            Mail::to($email)->send(new ReservationStatusMail($reservation, $status, $note)); // FIX #2
        } catch (\Throwable $e) {
            Log::error('ReservationController: failed to send status email', [
                'reservation_id' => $reservation->id,
                'status'         => $status,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}