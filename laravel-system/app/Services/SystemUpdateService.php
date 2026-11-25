<?php

namespace App\Services;

use App\Models\BackupSnapshot;
use App\Models\SystemUpdate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SystemUpdateService
{
    public const BUSINESS_TABLES = [
        'settings',
        'branches',
        'users',
        'guardian_student',
        'academic_years',
        'academic_classes',
        'academic_subjects',
        'categories',
        'courses',
        'batches',
        'batch_teachers',
        'batch_schedules',
        'batch_classrooms',
        'batch_waitlists',
        'batch_transfers',
        'batch_announcements',
        'batch_notifications',
        'batch_activity_logs',
        'course_modules',
        'course_chapters',
        'course_assignments',
        'assignment_submissions',
        'course_announcements',
        'course_routines',
        'lessons',
        'enrollments',
        'student_admissions',
        'fee_invoices',
        'exams',
        'exam_questions',
        'exam_attempts',
        'question_bank_items',
        'community_posts',
        'scorm_packages',
        'scorm_attempts',
        'learner_rewards',
        'products',
        'orders',
        'ebooks',
        'claimed_ebooks',
        'coupons',
        'center_results',
        'reviews',
        'attendance_records',
        'payroll_records',
        'financial_records',
        'contact_messages',
        'custom_pages',
        'in_app_notifications',
        'audit_logs',
        'recycle_bin_items',
        'certificates',
    ];

    public function currentVersion(): string
    {
        try {
            if (Schema::hasTable('settings')) {
                $dbVer = DB::table('settings')->where('key', 'system_version')->value('value');
                if (is_string($dbVer) && trim($dbVer) !== '') {
                    return trim($dbVer);
                }
            }
        } catch (\Throwable) {
            // Fall through to config file version
        }

        return (string) config('version.version', '1.0.0');
    }

    public function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles($this->migrationPaths());
            if (!Schema::hasTable('migrations')) {
                return array_keys($files);
            }
            $ran = $migrator->getRepository()->getRan();
            $pending = [];
            foreach ($files as $name => $path) {
                if (!in_array($name, $ran, true)) {
                    $pending[] = $name;
                }
            }
            return $pending;
        } catch (\Throwable) {
            return [];
        }
    }

    public function createFullBackup(string $actorName, string $label = 'Verified Full Database Backup'): array
    {
        $tables = [];
        $counts = [];

        foreach (self::BUSINESS_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
            $tables[$table] = $rows;
            $counts[$table] = count($rows);
        }

        $payload = [
            'format' => 'lms-full-backup-v1',
            'system_version' => $this->currentVersion(),
            'created_at' => now()->toIso8601String(),
            'created_by' => $actorName,
            'table_counts' => $counts,
            'tables' => $tables,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode database backup payload.');
        }

        $checksum = hash('sha256', $json);
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Unable to create the private backup directory.');
        }
        @chmod($backupDir, 0700);

        $stamp = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $fileName = "backup-{$stamp}.json";
        $fullPath = $backupDir . DIRECTORY_SEPARATOR . $fileName;
        $temporaryPath = $fullPath . '.tmp';

        $written = file_put_contents($temporaryPath, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Failed to write the complete database backup file.');
        }
        @chmod($temporaryPath, 0600);

        $diskPayload = file_get_contents($temporaryPath);
        if (!is_string($diskPayload) || !hash_equals($checksum, hash('sha256', $diskPayload))) {
            @unlink($temporaryPath);
            throw new RuntimeException('Database backup file verification failed.');
        }
        if (!@rename($temporaryPath, $fullPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to finalize the verified database backup file.');
        }
        @chmod($fullPath, 0600);

        try {
            $snapshot = BackupSnapshot::create([
                'label' => $label,
                'reason' => 'manual',
                'checksum_sha256' => $checksum,
                'verified' => true,
                'created_by' => $actorName,
                'payload_json' => $json,
            ]);
        } catch (\Throwable $exception) {
            @unlink($fullPath);
            throw $exception;
        }

        return [
            'snapshot' => $snapshot,
            'file_path' => 'storage/app/backups/' . $fileName,
            'file_name' => $fileName,
            'checksum' => $checksum,
            'table_counts' => $counts,
        ];
    }

    public function verifyBackupSnapshot(BackupSnapshot $snapshot): array
    {
        if (!$snapshot->verified) {
            throw new RuntimeException('This backup snapshot is not marked as verified. Restore/download is blocked.');
        }

        $json = (string) $snapshot->payload_json;
        $computed = hash('sha256', $json);
        if (!hash_equals((string) $snapshot->checksum_sha256, $computed)) {
            throw new RuntimeException('Backup SHA-256 verification failed. Restore/download aborted.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Backup JSON payload is invalid.', 0, $exception);
        }

        if (!is_array($decoded)
            || ($decoded['format'] ?? null) !== 'lms-full-backup-v1'
            || !is_array($decoded['tables'] ?? null)
            || !is_array($decoded['table_counts'] ?? null)
        ) {
            throw new RuntimeException('Unsupported or malformed backup snapshot format.');
        }

        $tablesData = $decoded['tables'];
        $tableCounts = $decoded['table_counts'];
        if (array_diff(array_keys($tablesData), array_keys($tableCounts))
            || array_diff(array_keys($tableCounts), array_keys($tablesData))
        ) {
            throw new RuntimeException('Backup table-count manifest does not match the table payload.');
        }

        $allowedTables = array_flip(self::BUSINESS_TABLES);
        $validatedTables = [];

        foreach ($tablesData as $table => $rows) {
            if (!isset($allowedTables[$table])) {
                throw new RuntimeException("Backup contains an unsupported table: {$table}");
            }
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new RuntimeException("Backup rows for {$table} are malformed.");
            }
            if (!is_numeric($tableCounts[$table]) || (int) $tableCounts[$table] !== count($rows)) {
                throw new RuntimeException("Backup row count verification failed for {$table}.");
            }

            if (!Schema::hasTable($table)) {
                if (count($rows) > 0) {
                    throw new RuntimeException("Cannot restore {$table}: the table is not present in the current schema.");
                }
                $validatedTables[$table] = [];
                continue;
            }

            $validColumns = array_flip(Schema::getColumnListing($table));
            $filteredRows = [];
            foreach ($rows as $index => $row) {
                if (!is_array($row) || array_is_list($row)) {
                    throw new RuntimeException("Backup row {$index} for {$table} is malformed.");
                }
                $filtered = array_intersect_key($row, $validColumns);
                if ($filtered === []) {
                    throw new RuntimeException("Backup row {$index} for {$table} has no valid columns.");
                }
                $filteredRows[] = $filtered;
            }
            $validatedTables[$table] = $filteredRows;
        }

        return [
            'payload' => $decoded,
            'tables' => $validatedTables,
        ];
    }

    public function restoreBackupSnapshot(BackupSnapshot $snapshot, string $actorName): array
    {
        $verified = $this->verifyBackupSnapshot($snapshot);
        $tablesData = $verified['tables'];

        // Preserve the current live state before any destructive restore work.
        $this->createFullBackup($actorName, "Pre-Restore Safety Snapshot (before restoring #{$snapshot->id})");

        $restoredCounts = [];
        DB::transaction(function () use ($tablesData, &$restoredCounts) {
            Schema::disableForeignKeyConstraints();
            try {
                // Clear every currently supported business table. Tables absent
                // from an older snapshot correctly return to an empty state.
                foreach (array_reverse(self::BUSINESS_TABLES) as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                foreach (self::BUSINESS_TABLES as $table) {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }

                    $rows = $tablesData[$table] ?? [];
                    if ($rows === []) {
                        $restoredCounts[$table] = 0;
                        continue;
                    }

                    foreach (array_chunk($rows, 100) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                    $restoredCounts[$table] = count($rows);

                    if (DB::getDriverName() === 'pgsql' && Schema::hasColumn($table, 'id')) {
                        try {
                            DB::statement(
                                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))"
                            );
                        } catch (\Throwable) {
                            // Ignore tables without serial sequences.
                        }
                    }
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        });

        return $restoredCounts;
    }

    public function inspectUpdateZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension (ZipArchive) is required for package updates.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open update ZIP archive.');
        }

        $entries = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if (str_contains($name, '..') || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new RuntimeException("Unsafe path detected inside update archive: {$name}");
                }
                $entries[] = $name;
            }

            $stripPrefix = $this->detectWrapperPrefix($entries);
            $manifest = [];
            foreach ($entries as $entry) {
                $rel = $stripPrefix !== '' ? substr($entry, strlen($stripPrefix)) : $entry;
                if ($rel === 'version.json' || $rel === 'update.json') {
                    $raw = $zip->getFromName($entry);
                    $parsed = is_string($raw) ? json_decode($raw, true) : null;
                    if (is_array($parsed)) {
                        $manifest = $parsed;
                    }
                    break;
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($entries)) {
            throw new RuntimeException('The uploaded update package is empty.');
        }

        $updatable = [];
        $protectedSkipped = [];
        foreach ($entries as $entry) {
            $rel = $stripPrefix !== '' ? substr($entry, strlen($stripPrefix)) : $entry;
            if ($rel === '') {
                continue;
            }
            if ($this->isProtectedPath($rel)) {
                $protectedSkipped[] = $rel;
            } else {
                $updatable[] = $rel;
            }
        }

        return [
            'strip_prefix' => $stripPrefix,
            'manifest' => $manifest,
            'updatable_files' => $updatable,
            'protected_skipped' => $protectedSkipped,
            'checksum_sha256' => hash_file('sha256', $zipPath),
        ];
    }

    public function applyUpdateZip(
        string $zipPath,
        string $packageName,
        string $actorName,
        ?string $targetBasePath = null,
        ?callable $postCopyHook = null
    ): SystemUpdate {
        $basePath = rtrim($targetBasePath ?? base_path(), DIRECTORY_SEPARATOR);
        $inspection = $this->inspectUpdateZip($zipPath);
        $manifest = $inspection['manifest'];

        if (!empty($manifest['min_php']) && version_compare(PHP_VERSION, (string) $manifest['min_php'], '<')) {
            throw new RuntimeException("Update requires PHP {$manifest['min_php']}+ (running " . PHP_VERSION . ').');
        }

        $fromVersion = $this->currentVersion();
        $toVersion = trim((string) ($manifest['version'] ?? '')) ?: $this->incrementPatchVersion($fromVersion);
        $checksum = $inspection['checksum_sha256'];

        $backup = $this->createFullBackup($actorName, "Pre-Update Snapshot ({$fromVersion} → {$toVersion})");

        $stamp = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $stagingDir = storage_path('app/update-staging/' . $stamp);
        $rollbackDir = storage_path('app/update-rollback/' . $stamp);
        $flagPath = storage_path('framework/updating.flag');

        mkdir($stagingDir, 0755, true);
        mkdir($rollbackDir, 0755, true);

        $overwrittenFiles = [];
        $newlyCreatedFiles = [];
        $filesUpdatedCount = 0;

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Unable to re-open update ZIP package.');
            }
            try {
                $zip->extractTo($stagingDir);
            } finally {
                $zip->close();
            }

            file_put_contents($flagPath, "Updating {$fromVersion} -> {$toVersion} at " . now()->toIso8601String());

            $prefix = $inspection['strip_prefix'];
            $ranBefore = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];

            foreach ($inspection['updatable_files'] as $relPath) {
                $source = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $prefix . $relPath);
                if (!is_file($source)) {
                    continue;
                }
                $dest = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                if (is_file($dest)) {
                    $rbTarget = $rollbackDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                    if (!is_dir(dirname($rbTarget))) {
                        mkdir(dirname($rbTarget), 0755, true);
                    }
                    copy($dest, $rbTarget);
                    $overwrittenFiles[] = $relPath;
                } else {
                    $newlyCreatedFiles[] = $relPath;
                }

                if (!copy($source, $dest)) {
                    throw new RuntimeException("Failed to write updated file: {$relPath}");
                }
                $filesUpdatedCount++;
            }

            if ($postCopyHook !== null) {
                $postCopyHook($basePath);
            }

            $migrationsDir = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
            if (is_dir($migrationsDir)) {
                $migrateArgs = ['--force' => true];
                if ($targetBasePath !== null) {
                    $migrateArgs['--path'] = $migrationsDir;
                    $migrateArgs['--realpath'] = true;
                }
                Artisan::call('migrate', $migrateArgs);
            }

            $ranAfter = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $migrationsApplied = array_values(array_diff($ranAfter, $ranBefore));

            if (Schema::hasTable('settings')) {
                DB::table('settings')->updateOrInsert(['key' => 'system_version'], ['value' => $toVersion]);
            }

            $record = SystemUpdate::create([
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_name' => $packageName,
                'checksum_sha256' => $checksum,
                'files_updated_count' => $filesUpdatedCount,
                'migrations_applied' => $migrationsApplied,
                'backup_snapshot_id' => $backup['snapshot']->id,
                'backup_file_path' => $backup['file_path'],
                'status' => 'applied',
                'notes' => trim((string) ($manifest['notes'] ?? 'Non-destructive package upgrade applied cleanly.')),
                'applied_by' => $actorName,
                'applied_at' => now(),
            ]);

            if ($targetBasePath === null) {
                try {
                    Artisan::call('view:clear');
                    Artisan::call('route:clear');
                    Artisan::call('config:clear');
                } catch (\Throwable) {
                    // Safe to ignore in CLI/test contexts
                }
            }

            return $record;
        } catch (\Throwable $e) {
            foreach ($overwrittenFiles as $relPath) {
                $rb = $rollbackDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                $dest = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                if (is_file($rb)) {
                    @copy($rb, $dest);
                }
            }
            foreach ($newlyCreatedFiles as $relPath) {
                $dest = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                if (is_file($dest)) {
                    @unlink($dest);
                }
            }

            SystemUpdate::create([
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_name' => $packageName,
                'checksum_sha256' => $checksum,
                'files_updated_count' => 0,
                'migrations_applied' => [],
                'backup_snapshot_id' => $backup['snapshot']->id,
                'backup_file_path' => $backup['file_path'],
                'status' => 'rolled_back',
                'notes' => 'Rolled back automatically after error: ' . $e->getMessage(),
                'applied_by' => $actorName,
                'applied_at' => now(),
            ]);

            throw $e;
        } finally {
            @unlink($flagPath);
            $this->deleteDirectory($stagingDir);
            $this->deleteDirectory($rollbackDir);
        }
    }

    public function runPendingMigrationsSafely(string $actorName): array
    {
        $pendingBefore = $this->pendingMigrations();
        $backup = $this->createFullBackup($actorName, 'Pre-Migration Safety Snapshot');

        Artisan::call('migrate', ['--force' => true]);

        return [
            'backup_id' => $backup['snapshot']->id,
            'migrations_applied' => $pendingBefore,
        ];
    }

    public function isProtectedPath(string $relativePath): bool
    {
        $norm = str_replace('\\', '/', $relativePath);
        while (str_starts_with($norm, './')) {
            $norm = substr($norm, 2);
        }
        $norm = ltrim($norm, '/');

        if (
            $norm === ''
            || $norm === '.htaccess'
            || str_starts_with($norm, '.env')
            || str_starts_with($norm, '.git')
            || $norm === 'storage/installed.lock'
        ) {
            return true;
        }

        if (str_ends_with($norm, '.sqlite') || str_ends_with($norm, '.db')) {
            return true;
        }

        $protected = config('version.protected_paths', [
            '.env',
            'storage',
            'bootstrap/cache',
            'public/uploads',
            'vendor',
        ]);

        foreach ($protected as $prefix) {
            $clean = rtrim(str_replace('\\', '/', $prefix), '/');
            if ($norm === $clean || str_starts_with($norm, $clean . '/')) {
                return true;
            }
        }

        return false;
    }

    private function detectWrapperPrefix(array $entries): string
    {
        if (empty($entries)) {
            return '';
        }

        $firstSegments = [];
        foreach ($entries as $entry) {
            if (!str_contains($entry, '/')) {
                return '';
            }
            $firstSegments[explode('/', $entry, 2)[0]] = true;
            if (count($firstSegments) > 1) {
                return '';
            }
        }

        $prefix = array_key_first($firstSegments) . '/';
        $recognizedRoots = ['app/', 'config/', 'database/', 'resources/', 'routes/', 'public/', 'version.json', 'update.json', 'composer.json'];
        foreach ($entries as $entry) {
            $sub = substr($entry, strlen($prefix));
            foreach ($recognizedRoots as $root) {
                if ($sub === $root || str_starts_with($sub, $root)) {
                    return $prefix;
                }
            }
        }

        return '';
    }

    private function migrationPaths(): array
    {
        $migrator = app('migrator');
        return array_merge([database_path('migrations')], $migrator->paths());
    }

    private function incrementPatchVersion(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            return $m[1] . '.' . $m[2] . '.' . ((int) $m[3] + 1);
        }
        return $version . '.1';
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }
}
