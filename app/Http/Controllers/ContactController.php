<?php

namespace App\Http\Controllers;

use App\Events\ContactSubmitted;
use App\Mail\ContactReplyMail;
use App\Models\Contact;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    /**
     * GET /api/admin/contacts/stats
     * Quick counts for the admin dashboard contact widget.
     *
     * IMPORTANT: this route MUST be registered BEFORE /{id} in api.php,
     * otherwise Laravel will try to bind "stats" as the {id} wildcard and
     * return a 404 (or a model-not-found 404 if using route–model binding).
     *
     *   Route::get('/stats', [ContactController::class, 'stats']);  ← first
     *   Route::get('/{id}',  [ContactController::class, 'show']);   ← second
     */
    public function stats()
    {
        try {
            $counts = Contact::select('status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'total'   => array_sum($counts),
                    'unread'  => (int) ($counts[Contact::STATUS_UNREAD]  ?? 0),
                    'read'    => (int) ($counts[Contact::STATUS_READ]    ?? 0),
                    'replied' => (int) ($counts[Contact::STATUS_REPLIED] ?? 0),
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@stats: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/contacts
     * Admin/staff: list all contact messages with filters.
     */
    public function index(Request $request)
    {
        try {
            $contacts = Contact::with(['user:id,name', 'repliedBy:id,name'])
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $contacts], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/contacts/{id}
     * Admin/staff: view one message and auto-mark it as read.
     */
    public function show($id)
    {
        try {
            $contact = Contact::with(['user:id,name', 'repliedBy:id,name'])->findOrFail($id);

            if ($contact->status === Contact::STATUS_UNREAD) {
                $contact->update(['status' => Contact::STATUS_READ]);
            }

            return response()->json(['status' => 'success', 'data' => $contact], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@show: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/contacts  ← public route (guests allowed)
     *
     * FIX: route was '/contects' (typo). Corrected in routes/api.php.
     * FIX: fires ContactSubmitted event after commit so the admin dashboard
     *      picks up the new message in real time without needing a dedicated
     *      frontend channel — the event piggybacks on the existing 'orders'
     *      channel that useEcho already listens to.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:20',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ]);

        try {
            $contact = Contact::create([
                'user_id' => Auth::id(), // null for guests — intentional
                'name'    => $validated['name'],
                'email'   => $validated['email'],
                'phone'   => $validated['phone'] ?? null,
                'subject' => $validated['subject'] ?? null,
                'message' => $validated['message'],
                'status'  => Contact::STATUS_UNREAD,
            ]);

            // Nudge the admin dashboard in real time so the unread badge
            // updates immediately. Wrapped in try/catch so a Reverb outage
            // never causes the contact submission itself to fail.
            try {
                event(new ContactSubmitted($contact));
            } catch (\Throwable $broadcastError) {
                Log::warning('ContactController@store broadcast failed: ' . $broadcastError->getMessage());
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Your message has been sent successfully.',
                'data'    => $contact,
            ], 201);

        } catch (\Throwable $th) {
            Log::error('ContactController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/contacts/{id}/reply
     * Admin replies to a contact message.
     *
     * FIX: previously only notified logged-in users in-app; guests
     * (user_id === null, the common case for a public contact form)
     * received nothing at all. Now ALWAYS emails the contact's email
     * address — the one channel guaranteed to exist — and additionally
     * creates an in-app notification when the sender was logged in.
     */
    public function reply(Request $request, $id)
    {
        $validated = $request->validate([
            'reply_message' => 'required|string',
        ]);

        try {
            $contact = Contact::with('user')->findOrFail($id);

            $contact->update([
                'reply_message' => $validated['reply_message'],
                'replied_by'    => Auth::id(),
                'replied_at'    => now(),
                'status'        => Contact::STATUS_REPLIED,
            ]);

            // Always email — works for both guests and logged-in users,
            // since 'email' is a required field on the contact form.
            try {
                Mail::to($contact->email)->send(new ContactReplyMail($contact));
            } catch (\Throwable $mailError) {
                // Reply is still saved even if SMTP is down; log it so
                // it doesn't fail silently and the admin can be alerted.
                Log::error('ContactController@reply mail failed: ' . $mailError->getMessage());
            }

            // Additionally notify in-app if the sender was logged in.
            if ($contact->user_id) {
                Notification::create([
                    'user_id'        => $contact->user_id,
                    'title'          => 'Reply to your message',
                    'message'        => "We've replied to your message" .
                        ($contact->subject ? ": \"{$contact->subject}\"" : '.'),
                    'type'           => Notification::TYPE_GENERAL,
                    'reference_id'   => $contact->id,
                    'reference_type' => 'contact',
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Reply sent successfully.',
                'data'    => $contact->fresh(['user:id,name', 'repliedBy:id,name']),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@reply: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/admin/contacts/{id}
     * Admin hard-deletes a contact message.
     */
    public function destroy($id)
    {
        try {
            Contact::findOrFail($id)->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Contact message deleted.',
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}