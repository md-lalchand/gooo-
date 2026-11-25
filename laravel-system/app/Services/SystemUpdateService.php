<?php

namespace App\Services;

use App\Models\BackupSnapshot;
use App\Models\SystemUpdate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
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

        try {
            $archivePath = $this->createPortableBackupArchive($snapshot, $json);
        } catch (\Throwable $exception) {
            $snapshot->delete();
            @unlink($fullPath);
            throw $exception;
        }

        return [
            'snapshot' => $snapshot,
            'file_path' => 'storage/app/backups/' . $fileName,
            'archive_path' => $archivePath,
            'file_name' => $fileName,
            'checksum' => $checksum,
            'table_counts' => $counts,
        ];
    }

    private function createPortableBackupArchive(BackupSnapshot $snapshot, string $databaseJson): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for portable backups.');
        }
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Unable to create the private backup directory.');
        }
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . 'backup-' . $snapshot->id . '.zip';
        $temporaryPath = $archivePath . '.tmp';
        $zip = new ZipArchive();
        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create portable backup archive.');
        }

        $entries = ['database.json' => $databaseJson];
        $envPath = base_path('.env');
        if (!is_file($envPath) || !is_readable($envPath)) {
            $zip->close();
            @unlink($temporaryPath);
            throw new RuntimeException('The application environment file is missing or unreadable; portable backup aborted.');
        }
        $envContents = file_get_contents($envPath);
        if (!is_string($envContents)) {
            $zip->close();
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to read the application environment file.');
        }
        $entries['environment/.env.encrypted'] = Crypt::encryptString($envContents);

        foreach ([
            'storage-app-public' => storage_path('app/public'),
            'public-uploads' => public_path('uploads'),
            'data-uploads' => base_path('data/uploads'),
        ] as $rootName => $rootPath) {
            $entries['uploads/' . $rootName . '/.backup-root'] = 'root';
            if (!is_dir($rootPath)) {
                continue;
            }
            if (is_link($rootPath)) {
                $zip->close();
                @unlink($temporaryPath);
                throw new RuntimeException("Refusing to back up a symlinked upload directory: {$rootName}");
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                if ($item->isLink()) {
                    $zip->close();
                    @unlink($temporaryPath);
                    throw new RuntimeException("Refusing to back up a symlinked upload file: {$rootName}");
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($rootPath) + 1));
                if ($relative === '' || str_contains($relative, '../')) {
                    $zip->close();
                    @unlink($temporaryPath);
                    throw new RuntimeException('Unsafe upload path encountered during backup.');
                }
                $content = file_get_contents($item->getPathname());
                if (!is_string($content)) {
                    $zip->close();
                    @unlink($temporaryPath);
                    throw new RuntimeException("Unable to read upload file: {$relative}");
                }
                $entries['uploads/' . $rootName . '/' . $relative] = $content;
            }
        }

        $manifestEntries = [];
        foreach ($entries as $name => $content) {
            $manifestEntries[$name] = hash('sha256', $content);
            if (!$zip->addFromString($name, $content)) {
                $zip->close();
                @unlink($temporaryPath);
                throw new RuntimeException("Unable to add backup entry: {$name}");
            }
        }
        $manifest = json_encode([
            'format' => 'lms-portable-backup-v1',
            'snapshot_id' => $snapshot->id,
            'created_at' => now()->toIso8601String(),
            'entries' => $manifestEntries,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($manifest) || !$zip->addFromString('manifest.json', $manifest) || !$zip->close()) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to finalize portable backup archive.');
        }

        $archiveHash = hash_file('sha256', $temporaryPath);
        if (!is_string($archiveHash) || !rename($temporaryPath, $archivePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to verify and finalize portable backup archive.');
        }
        @chmod($archivePath, 0600);
        if (!hash_equals($archiveHash, (string) hash_file('sha256', $archivePath))) {
            @unlink($archivePath);
            throw new RuntimeException('Portable backup archive checksum verification failed.');
        }
        return $archivePath;
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

    public function verifyPortableBackupArchive(BackupSnapshot $snapshot): void
    {
        $this->verifyBackupSnapshot($snapshot);
        $archivePath = storage_path('app/backups/backup-' . $snapshot->id . '.zip');
        if (!is_file($archivePath)) {
            throw new RuntimeException('Portable backup archive is missing.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Portable backup archive cannot be opened.');
        }
        try {
            $manifestJson = $zip->getFromName('manifest.json');
            $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
            if (!is_array($manifest)
                || ($manifest['format'] ?? null) !== 'lms-portable-backup-v1'
                || (int) ($manifest['snapshot_id'] ?? 0) !== (int) $snapshot->id
                || !is_array($manifest['entries'] ?? null)
            ) {
                throw new RuntimeException('Portable backup manifest is invalid.');
            }
            foreach ($manifest['entries'] as $name => $expectedHash) {
                if (!is_string($name) || str_starts_with($name, '/') || str_contains($name, '..')
                    || str_contains($name, '\\') || !is_string($expectedHash)
                ) {
                    throw new RuntimeException('Portable backup contains an unsafe entry path.');
                }
                $content = $zip->getFromName($name);
                if (!is_string($content) || !hash_equals($expectedHash, hash('sha256', $content))) {
                    throw new RuntimeException("Portable backup checksum verification failed for {$name}.");
                }
            }
        } finally {
            $zip->close();
        }
    }

    public function restorePortableBackup(BackupSnapshot $snapshot, string $actorName): array
    {
        $this->verifyBackupSnapshot($snapshot);
        $archivePath = storage_path('app/backups/backup-' . $snapshot->id . '.zip');
        if (!is_file($archivePath) || !class_exists(ZipArchive::class)) {
            throw new RuntimeException('Verified portable backup archive is missing or ZIP support is unavailable.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open portable backup archive.');
        }
        $manifestJson = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
        if (!is_array($manifest)
            || ($manifest['format'] ?? null) !== 'lms-portable-backup-v1'
            || (int) ($manifest['snapshot_id'] ?? 0) !== (int) $snapshot->id
            || !is_array($manifest['entries'] ?? null)
        ) {
            $zip->close();
            throw new RuntimeException('Portable backup manifest is invalid.');
        }

        $verifiedEntries = [];
        foreach ($manifest['entries'] as $name => $expectedHash) {
            if (!is_string($name) || str_starts_with($name, '/') || str_contains($name, '..')
                || str_contains($name, '\\') || !is_string($expectedHash)
            ) {
                $zip->close();
                throw new RuntimeException('Portable backup contains an unsafe entry path.');
            }
            $content = $zip->getFromName($name);
            if (!is_string($content) || !hash_equals($expectedHash, hash('sha256', $content))) {
                $zip->close();
                throw new RuntimeException("Portable backup checksum verification failed for {$name}.");
            }
            $verifiedEntries[$name] = $content;
        }
        if (($verifiedEntries['database.json'] ?? null) !== (string) $snapshot->payload_json
            || !isset($verifiedEntries['environment/.env.encrypted'])
        ) {
            $zip->close();
            throw new RuntimeException('Portable archive does not match the verified database snapshot.');
        }
        try {
            $environmentContents = Crypt::decryptString($verifiedEntries['environment/.env.encrypted']);
        } catch (\Throwable $exception) {
            $zip->close();
            throw new RuntimeException('Encrypted environment backup cannot be decrypted with the current application key.', 0, $exception);
        }
        $zip->close();

        $stagingRoot = storage_path('app/restore-staging-' . $snapshot->id . '-' . bin2hex(random_bytes(4)));
        $rollbackRoot = storage_path('app/restore-rollback-' . $snapshot->id . '-' . bin2hex(random_bytes(4)));
        $targets = [
            'storage-app-public' => storage_path('app/public'),
            'public-uploads' => public_path('uploads'),
            'data-uploads' => base_path('data/uploads'),
        ];
        $staged = [];
        foreach ($targets as $rootName => $targetPath) {
            $stagePath = $stagingRoot . DIRECTORY_SEPARATOR . $rootName;
            if (!mkdir($stagePath, 0700, true) && !is_dir($stagePath)) {
                throw new RuntimeException('Unable to prepare upload restore staging directory.');
            }
            $prefix = 'uploads/' . $rootName . '/';
            foreach ($verifiedEntries as $name => $content) {
                if (!str_starts_with($name, $prefix) || str_ends_with($name, '/.backup-root')) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                $segments = explode('/', $relative);
                if ($relative === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
                    throw new RuntimeException('Unsafe upload restore path detected.');
                }
                $destination = $stagePath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create upload restore directory.');
                }
                if (file_put_contents($destination, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to stage an upload file for restore.');
                }
            }
            $staged[$rootName] = ['stage' => $stagePath, 'target' => $targetPath];
        }

        $environmentRestorePath = storage_path('app/backups/restored-environment-' . $snapshot->id . '.env');
        $envTemp = $environmentRestorePath . '.tmp';
        if (file_put_contents($envTemp, $environmentContents, LOCK_EX) === false || !rename($envTemp, $environmentRestorePath)) {
            @unlink($envTemp);
            throw new RuntimeException('Unable to write the decrypted environment restore copy.');
        }
        @chmod($environmentRestorePath, 0600);

        $this->createFullBackup($actorName, "Pre-Restore Safety Snapshot (before portable restore #{$snapshot->id})");
        $swapped = [];
        try {
            foreach ($staged as $rootName => $paths) {
                $target = $paths['target'];
                if (is_link($target)) {
                    throw new RuntimeException("Refusing to overwrite symlinked upload directory: {$rootName}");
                }
                $rollback = $rollbackRoot . DIRECTORY_SEPARATOR . $rootName;
                if (is_dir($target)) {
                    if (!is_dir(dirname($rollback)) && !mkdir(dirname($rollback), 0700, true) && !is_dir(dirname($rollback))) {
                        throw new RuntimeException('Unable to prepare upload rollback directory.');
                    }
                    if (!rename($target, $rollback)) {
                        throw new RuntimeException("Unable to preserve current upload directory: {$rootName}");
                    }
                }
                $swapped[$rootName] = ['target' => $target, 'rollback' => $rollback, 'had_target' => is_dir($rollback)];
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                    throw new RuntimeException('Unable to prepare upload destination directory.');
                }
                if (!rename($paths['stage'], $target)) {
                    throw new RuntimeException("Unable to activate restored upload directory: {$rootName}");
                }
                @chmod($target, 0750);
            }
            $restoredCounts = $this->restoreBackupSnapshot($snapshot, $actorName, false);
        } catch (\Throwable $exception) {
            foreach (array_reverse($swapped) as $swap) {
                if (is_dir($swap['target'])) {
                    $this->removeDirectoryTree($swap['target']);
                }
                if ($swap['had_target'] && is_dir($swap['rollback'])) {
                    @rename($swap['rollback'], $swap['target']);
                }
            }
            $this->removeDirectoryTree($stagingRoot);
            throw new RuntimeException('Portable restore failed; upload directories were rolled back. ' . $exception->getMessage(), 0, $exception);
        }

        $this->removeDirectoryTree($stagingRoot);
        $this->removeDirectoryTree($rollbackRoot);
        return [
            'table_counts' => $restoredCounts,
            'environment_restore_path' => $environmentRestorePath,
        ];
    }

    private function removeDirectoryTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    public function restoreBackupSnapshot(BackupSnapshot $snapshot, string $actorName, bool $createSafetyBackup = true): array
    {
        $verified = $this->verifyBackupSnapshot($snapshot);
        $tablesData = $verified['tables'];

        // Preserve the current live state before any destructive restore work.
        if ($createSafetyBackup) {
            $this->createFullBackup($actorName, "Pre-Restore Safety Snapshot (before restoring #{$snapshot->id})");
        }

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
        $seenPaths = [];
        $totalUncompressedBytes = 0;
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
                throw new RuntimeException('Update archive contains an invalid number of entries.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new RuntimeException('Unable to inspect an update archive entry.');
                }

                $rawName = (string) ($stat['name'] ?? '');
                $name = str_replace('\\', '/', $rawName);
                $trimmedName = rtrim($name, '/');
                if ($trimmedName === '' || str_contains($name, "\0") || preg_match('/[\x00-\x1F\x7F]/', $name)
                    || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)
                ) {
                    throw new RuntimeException('Unsafe path detected inside update archive.');
                }

                foreach (explode('/', $trimmedName) as $segment) {
                    if ($segment === '' || $segment === '.' || $segment === '..') {
                        throw new RuntimeException("Unsafe path detected inside update archive: {$name}");
                    }
                }

                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === ZipArchive::OPSYS_UNIX
                ) {
                    $fileType = ($attributes >> 16) & 0170000;
                    if ($fileType === 0120000) {
                        throw new RuntimeException("Symbolic links are not allowed in update archives: {$name}");
                    }
                }

                if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== 0) {
                    throw new RuntimeException("Encrypted update archive entries are not supported: {$name}");
                }

                $size = (int) ($stat['size'] ?? 0);
                if ($size < 0 || $size > 50 * 1024 * 1024) {
                    throw new RuntimeException("Update archive entry exceeds the allowed uncompressed size: {$name}");
                }
                $totalUncompressedBytes += $size;
                if ($totalUncompressedBytes > 250 * 1024 * 1024) {
                    throw new RuntimeException('Update archive exceeds the 250 MB uncompressed size limit.');
                }

                $normalizedName = rtrim($name, '/');
                if (isset($seenPaths[$normalizedName])) {
                    throw new RuntimeException("Duplicate path detected inside update archive: {$normalizedName}");
                }
                $seenPaths[$normalizedName] = true;

                if (str_ends_with($name, '/')) {
                    continue;
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
            'total_uncompressed_bytes' => $totalUncompressedBytes,
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
        if (!is_dir($basePath) || realpath($basePath) === false) {
            throw new RuntimeException('Update target directory does not exist.');
        }
        $basePath = realpath($basePath);

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

        if (!mkdir($stagingDir, 0700, true) && !is_dir($stagingDir)) {
            throw new RuntimeException('Unable to create update staging directory.');
        }
        if (!mkdir($rollbackDir, 0700, true) && !is_dir($rollbackDir)) {
            $this->deleteDirectory($stagingDir);
            throw new RuntimeException('Unable to create update rollback directory.');
        }
        @chmod($stagingDir, 0700);
        @chmod($rollbackDir, 0700);

        $overwrittenFiles = [];
        $newlyCreatedFiles = [];
        $filesUpdatedCount = 0;
        $ranBefore = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $migrationStarted = false;

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Unable to re-open update ZIP package.');
            }
            try {
                if (!$zip->extractTo($stagingDir)) {
                    throw new RuntimeException('Unable to extract update archive into the private staging directory.');
                }
            } finally {
                $zip->close();
            }
            $this->assertSafeExtractedTree($stagingDir);

            if (file_put_contents($flagPath, "Updating {$fromVersion} -> {$toVersion} at " . now()->toIso8601String(), LOCK_EX) === false) {
                throw new RuntimeException('Unable to enable the maintenance update lock.');
            }

            $prefix = $inspection['strip_prefix'];
            foreach ($inspection['updatable_files'] as $relPath) {
                $sourceRelative = $prefix . $relPath;
                $source = $this->assertSafeDestinationPath($stagingDir, $sourceRelative);
                if (!is_file($source)) {
                    continue;
                }

                $dest = $this->assertSafeDestinationPath($basePath, $relPath);
                $destDir = dirname($dest);
                if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                    throw new RuntimeException("Unable to create update destination directory for {$relPath}.");
                }
                $dest = $this->assertSafeDestinationPath($basePath, $relPath);

                if (is_file($dest)) {
                    $rollbackRelative = $relPath;
                    $rbTarget = $this->assertSafeDestinationPath($rollbackDir, $rollbackRelative);
                    if (!is_dir(dirname($rbTarget)) && !mkdir(dirname($rbTarget), 0700, true) && !is_dir(dirname($rbTarget))) {
                        throw new RuntimeException("Unable to create rollback directory for {$relPath}.");
                    }
                    $rbTarget = $this->assertSafeDestinationPath($rollbackDir, $rollbackRelative);
                    if (!copy($dest, $rbTarget)) {
                        throw new RuntimeException("Unable to preserve the original file before updating: {$relPath}");
                    }
                    @chmod($rbTarget, 0600);
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
                $migrationStarted = true;
                $migrationExitCode = Artisan::call('migrate', $migrateArgs);
                if ($migrationExitCode !== 0) {
                    throw new RuntimeException('One or more database migrations failed. The update package was not marked as applied.');
                }
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
                    // Safe to ignore in CLI/test contexts.
                }
            }

            return $record;
        } catch (\Throwable $e) {
            $rollbackWarnings = [];
            $ranAfterFailure = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $newMigrations = array_values(array_diff($ranAfterFailure, $ranBefore));

            if ($newMigrations !== []) {
                try {
                    $rollbackArgs = ['--step' => 1, '--force' => true];
                    $migrationsDir = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
                    if ($targetBasePath !== null && is_dir($migrationsDir)) {
                        $rollbackArgs['--path'] = $migrationsDir;
                        $rollbackArgs['--realpath'] = true;
                    }
                    $rollbackExitCode = Artisan::call('migrate:rollback', $rollbackArgs);
                    $remaining = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
                    if ($rollbackExitCode !== 0 || array_diff($remaining, $ranBefore) !== []) {
                        throw new RuntimeException('Newly applied migrations remain after rollback.');
                    }
                } catch (\Throwable $rollbackException) {
                    $rollbackWarnings[] = 'Automatic migration rollback failed: ' . $rollbackException->getMessage();
                }
            } elseif ($migrationStarted) {
                $rollbackWarnings[] = 'The failed migration was not recorded as applied; inspect the database schema and use the pre-update backup if it changed partially.';
            }

            foreach ($overwrittenFiles as $relPath) {
                try {
                    $rb = $this->assertSafeDestinationPath($rollbackDir, $relPath);
                    $dest = $this->assertSafeDestinationPath($basePath, $relPath);
                    if (is_file($rb) && !copy($rb, $dest)) {
                        $rollbackWarnings[] = "Unable to restore original file {$relPath}.";
                    }
                } catch (\Throwable $rollbackException) {
                    $rollbackWarnings[] = "Unable to safely restore {$relPath}: " . $rollbackException->getMessage();
                }
            }
            foreach ($newlyCreatedFiles as $relPath) {
                try {
                    $dest = $this->assertSafeDestinationPath($basePath, $relPath);
                    if (is_file($dest) && !@unlink($dest)) {
                        $rollbackWarnings[] = "Unable to remove newly created file {$relPath}.";
                    }
                } catch (\Throwable $rollbackException) {
                    $rollbackWarnings[] = "Unable to safely remove {$relPath}: " . $rollbackException->getMessage();
                }
            }

            $notes = 'Code files rolled back after error: ' . $e->getMessage();
            if ($rollbackWarnings !== []) {
                $notes .= ' | ' . implode(' | ', $rollbackWarnings);
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
                'status' => $rollbackWarnings === [] ? 'rolled_back' : 'rollback_failed',
                'notes' => $notes,
                'applied_by' => $actorName,
                'applied_at' => now(),
            ]);

            if ($rollbackWarnings !== []) {
                throw new RuntimeException($e->getMessage() . ' ' . implode(' ', $rollbackWarnings), 0, $e);
            }
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
        $ranBefore = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $backup = $this->createFullBackup($actorName, 'Pre-Migration Safety Snapshot');

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode !== 0) {
                throw new RuntimeException('One or more pending database migrations failed.');
            }

            $ranAfter = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $applied = array_values(array_diff($ranAfter, $ranBefore));
            $stillPending = array_values(array_intersect($pendingBefore, $this->pendingMigrations()));
            if ($stillPending !== []) {
                throw new RuntimeException('Some migrations remain pending: ' . implode(', ', $stillPending));
            }

            return [
                'backup_id' => $backup['snapshot']->id,
                'migrations_applied' => $applied,
            ];
        } catch (\Throwable $exception) {
            $ranAfterFailure = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $newMigrations = array_values(array_diff($ranAfterFailure, $ranBefore));
            $rollbackWarning = null;

            if ($newMigrations !== []) {
                try {
                    $rollbackExitCode = Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
                    $remaining = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
                    if ($rollbackExitCode !== 0 || array_diff($remaining, $ranBefore) !== []) {
                        throw new RuntimeException('Newly applied migration batch remains after rollback.');
                    }
                } catch (\Throwable $rollbackException) {
                    $rollbackWarning = 'Automatic migration rollback failed: ' . $rollbackException->getMessage();
                }
            } else {
                $rollbackWarning = 'The failing migration was not recorded as applied; inspect the schema for partial changes.';
            }

            $message = "Migration failed. Pre-migration backup #{$backup['snapshot']->id} was preserved. " . $exception->getMessage();
            if ($rollbackWarning !== null) {
                $message .= ' ' . $rollbackWarning;
            }
            throw new RuntimeException($message, 0, $exception);
        }
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
            'public/storage',
            'vendor',
            'node_modules',
        ]);

        foreach ($protected as $prefix) {
            $clean = rtrim(str_replace('\\', '/', $prefix), '/');
            if ($norm === $clean || str_starts_with($norm, $clean . '/')) {
                return true;
            }
        }

        return false;
    }


    private function assertSafeExtractedTree(string $directory): void
    {
        $root = realpath($directory);
        if ($root === false) {
            throw new RuntimeException('Update staging directory could not be resolved.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed in extracted update packages.');
            }
            $realPath = $item->getRealPath();
            if ($realPath === false || ($realPath !== $root && !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('An extracted update path resolves outside its staging directory.');
            }
        }
    }

    private function assertSafeDestinationPath(string $basePath, string $relativePath): string
    {
        $root = realpath($basePath);
        if ($root === false) {
            throw new RuntimeException('Update target directory could not be resolved.');
        }

        $normalized = str_replace('\\', '/', $relativePath);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)) {
            throw new RuntimeException('Unsafe update destination path.');
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Unsafe update destination path.');
            }
        }

        $fileName = array_pop($segments);
        $cursor = $root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException("Update destination contains a symbolic-link directory: {$segment}");
            }
            if (file_exists($cursor) && !is_dir($cursor)) {
                throw new RuntimeException('Update destination parent is not a directory.');
            }
            if (is_dir($cursor)) {
                $realDirectory = realpath($cursor);
                if ($realDirectory === false || ($realDirectory !== $root && !str_starts_with($realDirectory, $root . DIRECTORY_SEPARATOR))) {
                    throw new RuntimeException('Update destination resolves outside the application directory.');
                }
            }
        }

        $destination = $cursor . DIRECTORY_SEPARATOR . $fileName;
        if (is_link($destination)) {
            throw new RuntimeException('Update destination file is a symbolic link.');
        }
        if (file_exists($destination) && !is_file($destination)) {
            throw new RuntimeException('Update destination is not a regular file.');
        }

        return $destination;
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
