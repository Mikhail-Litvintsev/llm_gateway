<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Files;

use App\Components\Claude\Exceptions\FileNotFoundException;
use App\Components\Claude\Exceptions\FileOwnershipMismatchException;
use App\Components\Claude\Files\FilesRepository;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\FileSourceResolver;
use App\Models\FileRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-unit')]
final class FileSourceResolverTest extends TestCase
{
    private FilesRepository $repository;

    private FileSourceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(FilesRepository::class);
        $this->resolver = new FileSourceResolver($this->repository);
    }

    #[Test]
    public function our_file_id_resolves_to_anthropic_id(): void
    {
        $fileId = 'file_aAbBcCdDeEfFgGhHiIjJkKlL';
        $record = $this->makeFileRecord($fileId, 1, 'file_01ABC');

        $this->repository
            ->method('findByFileId')
            ->with($fileId)
            ->willReturn($record);

        $result = $this->resolver->resolve(
            ['type' => 'file', 'file_id' => $fileId],
            1,
            false,
        );

        $this->assertSame('file_01ABC', $result['file_id']);
        $this->assertSame('file', $result['type']);
    }

    #[Test]
    public function wrong_client_throws_ownership_exception(): void
    {
        $fileId = 'file_aAbBcCdDeEfFgGhHiIjJkKlL';
        $record = $this->makeFileRecord($fileId, 1, 'file_01ABC');

        $this->repository
            ->method('findByFileId')
            ->with($fileId)
            ->willReturn($record);

        $this->expectException(FileOwnershipMismatchException::class);
        $this->expectExceptionMessage('does not belong to requesting client');

        $this->resolver->resolve(
            ['type' => 'file', 'file_id' => $fileId],
            2,
            false,
        );
    }

    #[Test]
    public function not_found_throws_file_not_found_exception(): void
    {
        $fileId = 'file_aAbBcCdDeEfFgGhHiIjJkKlL';

        $this->repository
            ->method('findByFileId')
            ->with($fileId)
            ->willReturn(null);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage($fileId);

        $this->resolver->resolve(
            ['type' => 'file', 'file_id' => $fileId],
            1,
            false,
        );
    }

    #[Test]
    public function anthropic_format_id_without_feature_flag_throws(): void
    {
        $anthropicId = 'file_SomeVeryLongAnthropicFormatIdentifier123';

        $this->repository->expects($this->never())->method('findByFileId');

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Unknown file ID format');

        $this->resolver->resolve(
            ['type' => 'file', 'file_id' => $anthropicId],
            1,
            false,
        );
    }

    #[Test]
    public function anthropic_format_id_with_feature_flag_passes_through(): void
    {
        $anthropicId = 'file_SomeVeryLongAnthropicFormatIdentifier123';

        $this->repository->expects($this->never())->method('findByFileId');

        $result = $this->resolver->resolve(
            ['type' => 'file', 'file_id' => $anthropicId],
            1,
            true,
        );

        $this->assertSame($anthropicId, $result['file_id']);
        $this->assertSame('file', $result['type']);
    }

    private function makeFileRecord(string $fileId, int $clientId, string $anthropicFileId): FileRecord
    {
        $record = new FileRecord();
        $record->file_id = $fileId;
        $record->client_id = $clientId;
        $record->anthropic_file_id = $anthropicFileId;

        return $record;
    }
}
