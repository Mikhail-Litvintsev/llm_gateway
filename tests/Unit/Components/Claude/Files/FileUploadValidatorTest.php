<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Files;

use App\Components\Claude\Files\FilePurpose;
use App\Components\Claude\Files\FileUploadValidator;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-unit')]
final class FileUploadValidatorTest extends TestCase
{
    private FileUploadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.max_file_size_mb' => 500]);

        $this->validator = new FileUploadValidator();
    }

    #[Test]
    public function empty_file_returns_error(): void
    {
        $file = UploadedFile::fake()->create('empty.txt', 0, 'text/plain');

        $result = $this->validator->validate($file, FilePurpose::Other);

        $this->assertNotNull($result);
        $this->assertSame('empty_file', $result['code']);
        $this->assertSame(400, $result['status']);
    }

    #[Test]
    public function file_too_large_returns_error(): void
    {
        $file = UploadedFile::fake()->create('big.bin', 501 * 1024, 'application/octet-stream');

        $result = $this->validator->validate($file, FilePurpose::Other);

        $this->assertNotNull($result);
        $this->assertSame('file_too_large', $result['code']);
        $this->assertSame(413, $result['status']);
    }

    #[Test]
    public function vision_with_pdf_fails(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $result = $this->validator->validate($file, FilePurpose::Vision);

        $this->assertNotNull($result);
        $this->assertSame('mime_not_allowed_for_purpose', $result['code']);
    }

    #[Test]
    public function vision_with_png_passes(): void
    {
        $file = UploadedFile::fake()->createWithContent('image.png', $this->minimalPng());

        $result = $this->validator->validate($file, FilePurpose::Vision);

        $this->assertNull($result);
    }

    #[Test]
    public function other_purpose_accepts_any_mime(): void
    {
        $file = UploadedFile::fake()->create('data.xyz', 10, 'application/octet-stream');

        $result = $this->validator->validate($file, FilePurpose::Other);

        $this->assertNull($result);
    }

    #[Test]
    public function code_execution_input_with_csv_passes(): void
    {
        $file = UploadedFile::fake()->createWithContent('data.csv', "a,b,c\n1,2,3\n");

        $result = $this->validator->validate($file, FilePurpose::CodeExecutionInput);

        $this->assertNull($result);
    }

    private function minimalPng(): string
    {
        // Minimal valid 1x1 pixel PNG
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }
}
