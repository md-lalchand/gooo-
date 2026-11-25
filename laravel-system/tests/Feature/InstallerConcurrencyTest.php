<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallerConcurrencyTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = storage_path('framework/install.lock');
        if (!is_dir(dirname($this->lockPath))) {
            mkdir(dirname($this->lockPath), 0700, true);
        }
    }

    public function test_second_installer_request_is_rejected_while_an_install_is_running(): void
    {
        $handle = fopen($this->lockPath, 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $response = $this->post('/install/run', []);
            $response->assertStatus(409);
            $response->assertHeader('Retry-After', '10');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_installer_lock_is_released_after_validation_failure(): void
    {
        $first = $this->post('/install/run', []);
        $this->assertTrue(in_array($first->getStatusCode(), [302, 303, 422], true));

        $second = $this->post('/install/run', []);
        $this->assertNotSame(409, $second->getStatusCode());
    }
}
