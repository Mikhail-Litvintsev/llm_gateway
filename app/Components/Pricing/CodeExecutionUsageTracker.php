<?php

declare(strict_types=1);

namespace App\Components\Pricing;

use App\Components\Pricing\DTO\CodeExecutionConsumption;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;

final readonly class CodeExecutionUsageTracker
{
    private const string FEATURE_KEY = 'code_execution_hours';

    public function __construct(
        private DatabaseManager $db,
        private RedisFactory $redis,
    ) {}

    public function consume(int $workspaceId, float $hoursUsed): CodeExecutionConsumption
    {
        $yearMonth = date('Y-m');
        $pool = $this->poolSize();

        return $this->db->transaction(function () use ($workspaceId, $yearMonth, $hoursUsed, $pool): CodeExecutionConsumption {
            $currentValue = (float) $this->db->table('workspace_feature_usage')
                ->where('workspace_id', $workspaceId)
                ->where('year_month', $yearMonth)
                ->where('feature', self::FEATURE_KEY)
                ->lockForUpdate()
                ->value('value') ?? 0.0;

            $available = max(0.0, $pool - $currentValue);
            $fromFree = max(0.0, min($hoursUsed, $available));
            $billed = max(0.0, $hoursUsed - $fromFree);

            $this->db->statement(
                'INSERT INTO workspace_feature_usage (workspace_id, year_month, feature, `value`, updated_at) '
                .'VALUES (?, ?, ?, ?, NOW()) '
                .'ON DUPLICATE KEY UPDATE `value` = `value` + VALUES(`value`), updated_at = NOW()',
                [$workspaceId, $yearMonth, self::FEATURE_KEY, $hoursUsed],
            );

            $this->redis->connection()->incrbyfloat(
                $this->redisKey($workspaceId, $yearMonth),
                $hoursUsed,
            );

            return new CodeExecutionConsumption(
                billedHours: $billed,
                freeHoursRemainingAfter: max(0.0, $available - $hoursUsed),
            );
        });
    }

    public function hasFreeHoursRemaining(int $workspaceId): bool
    {
        $yearMonth = date('Y-m');
        $key = $this->redisKey($workspaceId, $yearMonth);

        $cached = $this->redis->connection()->get($key);

        if ($cached === null) {
            $dbValue = (float) $this->db->table('workspace_feature_usage')
                ->where('workspace_id', $workspaceId)
                ->where('year_month', $yearMonth)
                ->where('feature', self::FEATURE_KEY)
                ->value('value') ?? 0.0;

            $this->redis->connection()->setex($key, $this->secondsUntilMonthEnd(), (string) $dbValue);
            $cached = $dbValue;
        }

        return (float) $cached < $this->poolSize();
    }

    public function poolSize(): float
    {
        return (float) config('llm.pricing.code_execution.free_hours_per_month', 1550.0);
    }

    private function redisKey(int $workspaceId, string $yearMonth): string
    {
        return "workspace:$workspaceId:code_exec_hours:$yearMonth";
    }

    private function secondsUntilMonthEnd(): int
    {
        return max(1, (int) (strtotime('first day of next month') - time()));
    }
}
