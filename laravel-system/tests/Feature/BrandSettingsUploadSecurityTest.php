<?php

namespace Tests\Feature;

use App\Support\BrandSettings;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class BrandSettingsUploadSecurityTest extends TestCase
{
    public function test_svg_branding_upload_is_rejected_even_when_the_helper_is_called_directly(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'brand.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        try {
            BrandSettings::storeUploadedImage($file, 'test-brand');
            $this->fail('SVG uploads must never be copied into the public branding directory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('SVG', $exception->getMessage());
        }
    }
}
