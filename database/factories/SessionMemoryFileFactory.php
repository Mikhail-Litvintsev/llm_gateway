<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SessionMemoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

final class SessionMemoryFileFactory extends Factory
{
    protected $model = SessionMemoryFile::class;

    public function definition(): array
    {
        return [
            'session_id' => SessionFactory::new(),
            'path' => '/memories/notes/default.md',
            'content' => 'default content',
        ];
    }
}
