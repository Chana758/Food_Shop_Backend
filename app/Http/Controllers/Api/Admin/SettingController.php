<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        // ទាញយកទិន្នន័យពី Database មកដាក់ជា Key-Value pair
        $settings = Setting::all()->pluck('value', 'key');

        $defaultSettings = [
            'siteName' => 'My Application',
            'supportEmail' => 'support@example.com',
            'timezone' => 'Asia/Phnom_Penh',
            'currency' => 'USD',
            'emailNotifications' => true,
            'maintenanceMode' => false,
            'sessionTimeout' => 120,
        ];

        $merged = array_merge($defaultSettings, $settings->toArray());

        // បំលែងទិន្នន័យ Boolean ឲ្យត្រឹមត្រូវពេលផ្ញើទៅ Frontend
        $merged['emailNotifications'] = filter_var($merged['emailNotifications'], FILTER_VALIDATE_BOOLEAN);
        $merged['maintenanceMode'] = filter_var($merged['maintenanceMode'], FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'status' => 'success',
            'data' => $merged
        ]);
    }

    public function update(Request $request)
    {
        // យកទិន្នន័យទាំងអស់ដែលផ្ញើមកពី Request
        $data = $request->except(['_token', '_method']);

        foreach ($data as $key => $value) {
            // បំលែងតម្លៃ Boolean ទៅជា String '1' ឬ '0' មុនពេលរក្សាទុកក្នុង Database
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $value = json_encode($value); // เผื่อករណiradi ទិន្នន័យជា Array
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully'
        ]);
    }
}