@php
    $statusMeta = [
        'confirmed' => ['label' => 'Confirmed',     'color' => '#16a34a', 'icon' => '✅'],
        'rejected'  => ['label' => 'Not Available', 'color' => '#ef4444', 'icon' => '⚠️'],
        'completed' => ['label' => 'Completed',     'color' => '#2563eb', 'icon' => '🍽️'],
        'cancelled' => ['label' => 'Cancelled',     'color' => '#6b7280', 'icon' => '❌'],
    ][$status] ?? ['label' => ucfirst($status), 'color' => '#6b7280', 'icon' => 'ℹ️'];
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #f8fafc; margin: 0; padding: 0; }
        .wrapper { max-width: 520px; margin: 0 auto; padding: 32px 20px; }
        .card { background: #ffffff; border-radius: 16px; padding: 32px; border: 1px solid #eef1f4; }
        .badge {
            display: inline-block; padding: 6px 14px; border-radius: 999px;
            font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
            color: #fff; background: {{ $statusMeta['color'] }}; margin-bottom: 20px;
        }
        h1 { font-size: 20px; color: #1e292b; margin: 0 0 8px; }
        p  { color: #4b5563; font-size: 14px; line-height: 1.6; }
        table.details { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table.details td { padding: 8px 0; font-size: 14px; color: #374151; border-bottom: 1px solid #e5e7eb; }
        table.details td:first-child { color: #9ca3af; font-weight: 700; text-transform: uppercase; font-size: 11px; width: 120px; }
        .note-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px; margin-top: 20px; font-size: 14px; color: #166534; }
        .footer { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 24px; }
        .ref { font-family: monospace; font-size: 16px; font-weight: 800; color: #1e292b; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">

            <span class="badge">{{ $statusMeta['icon'] }} {{ $statusMeta['label'] }}</span>

            <h1>Hi {{ $reservation->user?->name ?? 'there' }},</h1>

            @if($status === 'confirmed')
                <p>Great news — your table reservation has been <strong>confirmed</strong>! We look forward to welcoming you.</p>
            @elseif($status === 'rejected')
                <p>We're sorry, but we're unable to accommodate your reservation for the requested time. Please feel free to book another time slot.</p>
            @elseif($status === 'completed')
                <p>Thank you for dining with us today. We hope you had a wonderful experience and we look forward to seeing you again!</p>
            @elseif($status === 'cancelled')
                <p>Your reservation has been cancelled. If this was a mistake, please make a new booking and we'll be happy to assist.</p>
            @else
                <p>Your reservation status has been updated to <strong>{{ $status }}</strong>.</p>
            @endif

            <table class="details">
                <tr>
                    <td>Reference</td>
                    <td class="ref">#{{ str_pad($reservation->id, 5, '0', STR_PAD_LEFT) }}</td>
                </tr>
                <tr>
                    <td>Table</td>
                    {{-- FIX: was $reservation->table->name — throws if table was deleted.
                         Changed to null-safe operator ?-> so it gracefully falls back. --}}
                    <td>{{ $reservation->table?->name ?? 'Table #' . $reservation->table_id }}</td>
                </tr>
                <tr>
                    <td>Guests</td>
                    <td>{{ $reservation->guest_count }} {{ $reservation->guest_count == 1 ? 'Person' : 'People' }}</td>
                </tr>
                <tr>
                    <td>Date</td>
                    <td>{{ \Carbon\Carbon::parse($reservation->reserved_at)->format('l, F j, Y') }}</td>
                </tr>
                <tr>
                    <td>Time</td>
                    <td>{{ \Carbon\Carbon::parse($reservation->reserved_at)->format('g:i A') }}</td>
                </tr>
                @if($status === 'confirmed' && $reservation->confirmedBy)
                <tr>
                    <td>Confirmed by</td>
                    <td>{{ $reservation->confirmedBy->name }}</td>
                </tr>
                @endif
            </table>

            @if(!empty($note))
                <div class="note-box">
                    <strong>Message from the restaurant:</strong><br><br>
                    {{ $note }}
                </div>
            @endif

        </div>

        <p class="footer">
            Khmer-Fresh Organic Store &middot; Phnom Penh, Cambodia<br>
            This is an automated message — please do not reply directly to this email.
        </p>
    </div>
</body>
</html>