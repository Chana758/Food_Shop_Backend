<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    private function resolveDisk(): array
    {
        $disks = config('backup.backup.destination.disks', []);
        $diskName = $disks[0] ?? 'local'; 
        $folderName = config('backup.backup.name', 'Laravel');

        return [Storage::disk($diskName), $folderName];
    }

    /**
     * GET /api/admin/backups
     */
    public function index()
    {
        try {
            [$disk, $folderName] = $this->resolveDisk();
            $files = $disk->files($folderName);

            $backups = [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.zip') || str_ends_with($file, '.sql')) {
                    $sizeBytes = $disk->size($file);
                    $sizeFormatted = $sizeBytes > 1024 * 1024
                        ? round($sizeBytes / 1024 / 1024, 2) . ' MB'
                        : round($sizeBytes / 1024, 2) . ' KB';

                    $backups[] = [
                        'name'   => basename($file),
                        'size'   => $sizeFormatted,
                        'type'   => str_contains($file, 'auto') ? 'Auto' : 'Manual',
                        'status' => 'Success',
                        'date'   => date('M d, Y — h:i A', $disk->lastModified($file)),
                        'path'   => $file,
                    ];
                }
            }

            $backups = array_reverse($backups);
            // assign id AFTER reverse so it displays sequentially (1,2,3...)
            foreach ($backups as $i => &$b) {
                $b['id'] = $i + 1;
            }
            unset($b);

            return response()->json([
                'status' => 'success',
                'data'   => $backups,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Fetch backups error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch backup history.',
            ], 500);
        }
    }

    /**
     * POST /api/admin/backups
     */
    public function store(Request $request)
    {
        try {
            Artisan::call('backup:run', ['--only-db' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'Database backup generated successfully!',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Run backup error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/backups/{fileName}/download
     */
    public function download($fileName)
    {
        [$disk, $folderName] = $this->resolveDisk();
        $filePath = $folderName . '/' . basename($fileName); // basename() guards path traversal

        if (!$disk->exists($filePath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Backup file not found.',
            ], 404);
        }

        return $disk->download($filePath);
    }

    /**
     * DELETE /api/admin/backups/{fileName}
     */
    public function destroy($fileName)
    {
        try {
            [$disk, $folderName] = $this->resolveDisk();
            $filePath = $folderName . '/' . basename($fileName);

            if ($disk->exists($filePath)) {
                $disk->delete($filePath);
                return response()->json([
                    'status' => 'success',
                    'message' => 'Backup deleted successfully.',
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'File not found.',
            ], 404);

        } catch (\Exception $e) {
            Log::error('Delete backup error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete backup.',
            ], 500);
        }
    }
}