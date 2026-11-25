<?php

namespace App\Http\Controllers;

use App\Models\BackupSnapshot;
use App\Services\SystemUpdateService;
use Illuminate\Http\Request;

class SystemUpdateController extends Controller
{
    public function __construct(private readonly SystemUpdateService $updater)
    {
    }

    public function createFullBackup(Request $request)
    {
        $label = trim((string) $request->input('label', 'Full Non-Destructive System Backup'));
        $result = $this->updater->createFullBackup($request->user()->name, $label ?: 'Full Non-Destructive System Backup');

        return back()->with('status', "Full portable backup created (#{$result['snapshot']->id}) with database, uploads, and encrypted environment. Archive SHA-256: " . substr((string) hash_file('sha256', $result['archive_path']), 0, 12) . "...");
    }

    public function downloadBackup(BackupSnapshot $snapshot)
    {
        $this->updater->verifyBackupSnapshot($snapshot);
        $path = storage_path('app/backups/backup-' . $snapshot->id . '.zip');
        abort_unless(is_file($path), 404, 'Portable backup archive not found.');
        $filename = 'lms-portable-backup-' . $snapshot->id . '-' . now()->format('Ymd-His') . '.zip';
        return response()->download($path, $filename, ['Content-Type' => 'application/zip']);
    }

    public function restoreBackup(Request $request, BackupSnapshot $snapshot)
    {
        try {
            $result = $this->updater->restorePortableBackup($snapshot, $request->user()->name);
            $counts = $result['table_counts'];
            $totalRows = array_sum($counts);
            return back()->with('status', "Portable backup #{$snapshot->id} restored ({$totalRows} database records). Uploads restored. The encrypted environment was decrypted to {$result['environment_restore_path']} for an administrator to review before manually replacing .env.");
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => $e->getMessage()]);
        }
    }

    public function runMigrations(Request $request)
    {
        try {
            $res = $this->updater->runPendingMigrationsSafely($request->user()->name);
            $applied = count($res['migrations_applied']);
            $msg = $applied > 0
                ? "Applied {$applied} new database migration(s) safely after creating Backup #{$res['backup_id']}. All existing records preserved."
                : "Database schema is already up to date (Backup #{$res['backup_id']} saved).";
            return back()->with('status', $msg);
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => 'Migration failed: ' . $e->getMessage()]);
        }
    }

    public function applyUpdate(Request $request)
    {
        $request->validate([
            'update_package' => 'required|file|mimes:zip|max:51200',
        ]);

        $file = $request->file('update_package');
        $packageName = $file->getClientOriginalName();

        try {
            $record = $this->updater->applyUpdateZip(
                $file->getRealPath(),
                $packageName,
                $request->user()->name
            );

            $migratedCount = count($record->migrations_applied ?? []);
            return back()->with(
                'status',
                "System updated cleanly from v{$record->from_version} to v{$record->to_version} ({$record->files_updated_count} file(s) updated, {$migratedCount} incremental migration(s) applied). Pre-update Backup #{$record->backup_snapshot_id} preserved all database records & uploads."
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => 'Update failed. Code rollback was attempted; inspect the update history and pre-update backup before retrying: ' . $e->getMessage()]);
        }
    }
}
