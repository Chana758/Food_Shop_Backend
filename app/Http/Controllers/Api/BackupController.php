<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    /**
     * Display a listing of the backup files.
     */
    public function index()
    {
        try {
            $diskName = config('backup.backup.destination.disks')[0] ?? 'local';
            $disk = Storage::disk($diskName);
            $folderName = config('backup.backup.name', 'Laravel');

            $files = $disk->files($folderName);

            $backups = [];
            foreach ($files as $index => $file) {
                if (str_ends_with($file, '.zip') || str_ends_with($file, '.sql')) {
                    $sizeBytes = $disk->size($file);
                    $sizeFormatted = $sizeBytes > 1024 * 1024 
                        ? round($sizeBytes / 1024 / 1024, 2) . ' MB' 
                        : round($sizeBytes / 1024, 2) . ' KB';

                    $backups[] = [
                        'id' => $index + 1,
                        'name' => basename($file),
                        'size' => $sizeFormatted,
                        'type' => str_contains($file, 'auto') ? 'Auto' : 'Manual',
                        'status' => 'Success',
                        'date' => date('M d, Y — h:i A', $disk->lastModified($file)),
                        'path' => $file,
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => array_reverse($backups)
            ], 200);

        } catch (\Exception $e) {
            Log::error('Fetch backups error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch backup history.'
            ], 500);
        }
    }

    /**
     * Run a new database backup immediately.
     */
    public function store(Request $request)
    {
        try {
            Artisan::call('backup:run', ['--only-db' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'Database backup generated successfully!'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Run backup error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Backup failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download a specific backup file.
     */
    public function download($fileName)
    {
        $diskName = config('backup.backup.destination.disks')[0] ?? 'local';
        $folderName = config('backup.backup.name', 'Laravel');
        $filePath = $folderName . '/' . $fileName;

        $disk = Storage::disk($diskName);

        if (!$disk->exists($filePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Backup file not found.'
            ], 404);
        }

        return $disk->download($filePath);
    }

    /**
     * Delete a specific backup file.
     */
    public function destroy($fileName)
    {
        try {
            $diskName = config('backup.backup.destination.disks')[0] ?? 'local';
            $folderName = config('backup.backup.name', 'Laravel');
            $filePath = $folderName . '/' . $fileName;

            $disk = Storage::disk($diskName);

            if ($disk->exists($filePath)) {
                $disk->delete($filePath);
                return response()->json([
                    'status' => 'success',
                    'message' => 'Backup deleted successfully.'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'File not found.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete backup.'
            ], 500);
        }
    }
}