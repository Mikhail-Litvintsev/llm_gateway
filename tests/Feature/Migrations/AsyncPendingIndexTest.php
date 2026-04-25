<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AsyncPendingIndexTest extends TestCase
{
    #[Test]
    public function new_composite_index_exists_with_correct_column_order(): void
    {
        $rows = DB::select('SHOW INDEX FROM async_pending WHERE Key_name = ?', [
            'async_pending_next_attempt_status_idx',
        ]);

        $this->assertCount(2, $rows, 'Expected composite index with 2 columns');

        $byPosition = [];
        foreach ($rows as $row) {
            $byPosition[(int) $row->Seq_in_index] = $row->Column_name;
        }

        ksort($byPosition);

        $this->assertSame('next_attempt_at', $byPosition[1] ?? null, 'First column must be next_attempt_at');
        $this->assertSame('status', $byPosition[2] ?? null, 'Second column must be status');
    }

    #[Test]
    public function old_composite_index_is_removed(): void
    {
        $rows = DB::select('SHOW INDEX FROM async_pending WHERE Key_name = ?', [
            'async_pending_status_next_attempt_at_index',
        ]);

        $this->assertCount(0, $rows, 'Old default-named composite index must not exist after migration');
    }

    #[Test]
    public function no_duplicate_indexes_on_status_columns(): void
    {
        $rows = DB::select('SHOW INDEX FROM async_pending');

        $compositeNames = [];
        foreach ($rows as $row) {
            if ($row->Column_name === 'status' || $row->Column_name === 'next_attempt_at') {
                $compositeNames[] = $row->Key_name;
            }
        }

        $unique = array_unique($compositeNames);

        $this->assertContains('async_pending_next_attempt_status_idx', $unique);
        $this->assertNotContains('async_pending_status_next_attempt_at_index', $unique);
    }
}
