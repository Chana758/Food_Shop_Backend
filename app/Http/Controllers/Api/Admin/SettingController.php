<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    // Only these keys can ever be written to the settings table.
    private const ALLOWED_KEYS = [
        'restaurant_name', 'tagline', 'phone', 'email', 'address',
        'currency', 'timezone', 'language',
        'notify_new_order', 'notify_low_stock', 'notify_payment',
        'notify_daily_report', 'sound_alert',
        'show_logo_receipt', 'show_tax_receipt', 'tax_rate', 'receipt_note',
        'table_service', 'takeaway',
        'dark_mode', 'compact_mode', 'accent_color',
        'theme_target', // 'sidebar' | 'page'
        'two_fa', 'auto_logout', 'logout_time',

        //  NEW — Backup & Recovery schedule
        'backup_frequency', // 'hourly' | 'daily' | 'weekly' | 'monthly'
        'backup_time',      // 'HH:mm'
        'backup_keep',      // integer, stored as string
    ];

    private const DEFAULTS = [
        'restaurant_name'    => 'Khmer-Fresh',
        'tagline'            => 'Authentic Traditional Food',
        'phone'              => '',
        'email'              => '',
        'address'            => '',
        'currency'           => 'USD',
        'timezone'           => 'Asia/Phnom_Penh',
        'language'           => 'en',
        'notify_new_order'   => true,
        'notify_low_stock'   => true,
        'notify_payment'     => false,
        'notify_daily_report'=> true,
        'sound_alert'        => true,
        'show_logo_receipt'  => true,
        'show_tax_receipt'   => false,
        'tax_rate'           => '10',
        'receipt_note'       => 'Thank you for dining with us!',
        'table_service'      => true,
        'takeaway'           => true,
        'dark_mode'          => false,
        'compact_mode'       => false,
        'accent_color'       => '#10b981',
        'theme_target'       => 'sidebar',
        'two_fa'             => false,
        'auto_logout'        => true,
        'logout_time'        => '30',

        // NEW — Backup & Recovery defaults
        'backup_frequency'   => 'daily',
        'backup_time'        => '02:00',
        'backup_keep'        => '7',
    ];

    // Keys stored as text in DB but represent booleans in the frontend.
    private const BOOLEAN_KEYS = [
        'notify_new_order', 'notify_low_stock', 'notify_payment',
        'notify_daily_report', 'sound_alert',
        'show_logo_receipt', 'show_tax_receipt',
        'table_service', 'takeaway',
        'dark_mode', 'compact_mode', 'two_fa', 'auto_logout',
    ];

    // String-enum keys, validated against a fixed allowlist.
    private const ENUM_KEYS = [
        'theme_target'     => ['sidebar', 'page'],
        'backup_frequency' => ['hourly', 'daily', 'weekly', 'monthly'], //  NEW
    ];

    /**
     * GET /api/admin/settings
     */
    public function index()
    {
        try {
            $settings = Setting::all()->pluck('value', 'key');
            $merged   = array_merge(self::DEFAULTS, $settings->toArray());

            foreach (self::BOOLEAN_KEYS as $key) {
                $merged[$key] = filter_var($merged[$key], FILTER_VALIDATE_BOOLEAN);
            }

            foreach (self::ENUM_KEYS as $key => $allowed) {
                if (!in_array($merged[$key] ?? null, $allowed, true)) {
                    $merged[$key] = self::DEFAULTS[$key];
                }
            }

            return response()->json([
                'status' => 'success',
                'data'   => $merged,
            ]);

        } catch (\Throwable $th) {
            Log::error('SettingController@index: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load settings.',
            ], 500);
        }
    }

    /**
     * PUT /api/admin/settings
     */
    public function update(Request $request)
    {
        try {
            //  NEW — light validation for backup fields before whitelisting
            $request->validate([
                'backup_frequency' => 'sometimes|string|in:hourly,daily,weekly,monthly',
                'backup_time'      => 'sometimes|date_format:H:i',
                'backup_keep'      => 'sometimes|integer|min:1|max:365',
            ]);

            $data = $request->only(self::ALLOWED_KEYS);

            if (empty($data)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No valid settings fields were provided.',
                ], 422);
            }

            foreach (self::ENUM_KEYS as $key => $allowed) {
                if (array_key_exists($key, $data) && !in_array($data[$key], $allowed, true)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Invalid value for {$key}. Allowed: " . implode(', ', $allowed),
                    ], 422);
                }
            }

            foreach ($data as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                } elseif ($value === null) {
                    $value = '';
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $value]
                );
            }

            $freshSettings = Setting::all()->pluck('value', 'key');
            $merged        = array_merge(self::DEFAULTS, $freshSettings->toArray());
            foreach (self::BOOLEAN_KEYS as $key) {
                $merged[$key] = filter_var($merged[$key], FILTER_VALIDATE_BOOLEAN);
            }
            foreach (self::ENUM_KEYS as $key => $allowed) {
                if (!in_array($merged[$key] ?? null, $allowed, true)) {
                    $merged[$key] = self::DEFAULTS[$key];
                }
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Settings updated successfully',
                'data'    => $merged,
            ]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'status'  => 'error',
                'message' => $ve->validator->errors()->first(),
            ], 422);

        } catch (\Throwable $th) {
            Log::error('SettingController@update: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to save settings: ' . $th->getMessage(),
            ], 500);
        }
    }
}