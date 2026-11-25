<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallerWizardTest extends TestCase
{
    private string $lockPath;
    private string $envPath;
    private string $sqliteTestPath;
    private ?string $originalLock = null;
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = storage_path('installed.lock');
        $this->envPath = base_path('.env');
        $this->sqliteTestPath = database_path('phpunit_installer_check.sqlite');

        if (is_file($this->lockPath)) {
            $this->originalLock = file_get_contents($this->lockPath) ?: '';
            @unlink($this->lockPath);
        }
        if (is_file($this->envPath)) {
            $this->originalEnv = file_get_contents($this->envPath) ?: '';
        }
        @unlink($this->sqliteTestPath);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        if ($this->originalLock !== null) {
            file_put_contents($this->lockPath, $this->originalLock);
        } else {
            @unlink($this->lockPath);
        }

        if ($this->originalEnv !== null) {
            file_put_contents($this->envPath, $this->originalEnv);
        } else {
            @unlink($this->envPath);
        }

        @unlink($this->sqliteTestPath);
        parent::tearDown();
    }


    public function test_installer_rejects_weak_super_admin_password(): void
    {
        $this->post('/install/run', [
            'company_name' => 'Secure Academy',
            'app_url' => 'https://secure.example.com',
            'support_email' => 'support@secure.example.com',
            'currency_symbol' => '৳',
            'roll_prefix' => 'SEC',
            'db_connection' => 'sqlite',
            'db_database' => $this->sqliteTestPath,
            'admin_name' => 'Admin User',
            'admin_email' => 'admin@secure.example.com',
            'admin_password' => 'weakpass',
        ])->assertSessionHasErrors('admin_password');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_postgres_verify_full_requires_a_readable_ca_certificate(): void
    {
        $this->post('/install/run', [
            'company_name' => 'Secure Academy',
            'app_url' => 'https://secure.example.com',
            'support_email' => 'support@secure.example.com',
            'currency_symbol' => '৳',
            'roll_prefix' => 'SEC',
            'db_connection' => 'pgsql',
            'db_database' => 'academy_lms',
            'db_host' => 'db.example.com',
            'db_sslmode' => 'verify-full',
            'admin_name' => 'Admin User',
            'admin_email' => 'admin@secure.example.com',
            'admin_password' => 'SecureAdmin123!',
        ])->assertSessionHasErrors('db_sslrootcert');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_remote_mysql_tls_requires_a_ca_certificate(): void
    {
        $this->post('/install/run', [
            'company_name' => 'Secure Academy',
            'app_url' => 'https://secure.example.com',
            'support_email' => 'support@secure.example.com',
            'currency_symbol' => '৳',
            'roll_prefix' => 'SEC',
            'db_connection' => 'mysql',
            'db_database' => 'academy_lms',
            'db_host' => 'db.example.com',
            'db_sslmode' => 'require',
            'admin_name' => 'Admin User',
            'admin_email' => 'admin@secure.example.com',
            'admin_password' => 'SecureAdmin123!',
        ])->assertSessionHasErrors('db_ssl_ca');

        $this->assertFileDoesNotExist($this->lockPath);
    }


    public function test_installer_does_not_write_install_lock_when_migrations_fail(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', ['--force' => true])
            ->andReturn(1);

        $this->post('/install/run', [
            'company_name' => 'Retry Academy',
            'app_url' => 'https://retry.example.com',
            'support_email' => 'support@retry.example.com',
            'currency_symbol' => '৳',
            'roll_prefix' => 'TRY',
            'db_connection' => 'sqlite',
            'db_database' => $this->sqliteTestPath,
            'admin_name' => 'Retry Admin',
            'admin_email' => 'admin@retry.example.com',
            'admin_password' => 'RetryAdmin123!',
        ])->assertSessionHasErrors('db');

        $this->assertFileDoesNotExist($this->lockPath);
        $this->assertFileExists($this->envPath);
    }

    public function test_uninstalled_app_redirects_to_install_wizard_and_completes_clean_white_label_setup(): void
    {
        $this->get('/')->assertRedirect(route('install.index'));
        $this->get('/install')->assertOk()->assertSee('Platform Installation');

        $response = $this->post('/install/run', [
            'company_name' => 'Apex Skills Institute',
            'tagline' => 'Practical Career Learning Hub',
            'app_url' => 'https://apexskills.example.com',
            'support_email' => 'hello@apexskills.example.com',
            'support_phone' => '+1-555-0199',
            'office_address' => '500 Innovation Way',
            'currency_symbol' => '€',
            'roll_prefix' => 'APEX',
            'primary_color' => '#0f172a',
            'accent_color' => '#2563eb',
            'db_connection' => 'sqlite',
            'db_database' => $this->sqliteTestPath,
            'admin_name' => 'Elena Vance',
            'admin_email' => 'elena@apexskills.example.com',
            'admin_password' => 'StrongPass123!',
        ]);

        $response->assertRedirect(route('login', ['installed' => 1]));
        $this->assertFileExists($this->lockPath);
        $this->assertFileExists($this->envPath);
        $this->assertFileExists($this->sqliteTestPath);

        $envContent = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('APP_KEY=base64:', $envContent);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $envContent);
        $this->assertStringContainsString('SESSION_HTTP_ONLY=true', $envContent);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $envContent);

        // Once installed, /install is locked and redirects to home
        $this->get('/install')->assertRedirect(route('home'));

        $pdo = new \PDO('sqlite:' . $this->sqliteTestPath);
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn());
        $this->assertSame('admin', (string) $pdo->query("SELECT role FROM users LIMIT 1")->fetchColumn());

        foreach (['branches', 'courses', 'batches', 'lessons', 'exams', 'products', 'ebooks', 'coupons', 'center_results', 'reviews', 'community_posts', 'certificates'] as $table) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $this->assertSame(0, $count, "Expected zero demo rows in {$table}");
        }

        $companySetting = (string) $pdo->query("SELECT value FROM settings WHERE key = 'company_name'")->fetchColumn();
        $currencySetting = (string) $pdo->query("SELECT value FROM settings WHERE key = 'currency_symbol'")->fetchColumn();
        $prefixSetting = (string) $pdo->query("SELECT value FROM settings WHERE key = 'roll_prefix'")->fetchColumn();
        $this->assertSame('Apex Skills Institute', $companySetting);
        $this->assertSame('€', $currencySetting);
        $this->assertSame('APEX', $prefixSetting);
    }
}
