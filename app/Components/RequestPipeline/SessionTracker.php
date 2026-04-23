<?php

namespace App\Components\RequestPipeline;

use App\Components\RequestPipeline\Exceptions\ValidationException;
use App\Models\SessionHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SessionTracker
{
    /**
     * Acquire a session lock, validate step_id, and register the step atomically.
     *
     * @throws ValidationException INVALID_STEP_ID or MISSING_STEP_ID
     */
    public function validateAndRegister(
        string $sessionId,
        int $stepId,
        int $clientId,
        int $requestLogId,
    ): void {
        $lockKey = "session_lock:{$clientId}:{$sessionId}";
        $lock = Cache::lock($lockKey, 10);

        if (!$lock->get()) {
            throw new ValidationException(
                'INVALID_STEP_ID',
                'Concurrent request for this session. Please retry.',
                409,
            );
        }

        try {
            $this->validateStep($sessionId, $stepId, $clientId);
            $this->registerStep($sessionId, $stepId, $clientId, $requestLogId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Validate that step_id is strictly greater than the last step for this session.
     *
     * @throws ValidationException INVALID_STEP_ID
     */
    public function validateStep(string $sessionId, int $stepId, int $clientId): void
    {
        $lastStep = SessionHistory::where('session_id', $sessionId)
            ->where('api_client_id', $clientId)
            ->max('step_id');

        if ($lastStep !== null && $stepId <= $lastStep) {
            throw new ValidationException(
                'INVALID_STEP_ID',
                "step_id must be strictly greater than previous ({$lastStep}). Got: {$stepId}.",
                400,
                ['current_step_id' => $stepId, 'last_step_id' => $lastStep],
            );
        }
    }

    /**
     * Register a session step.
     */
    public function registerStep(string $sessionId, int $stepId, int $clientId, int $requestLogId): void
    {
        SessionHistory::create([
            'session_id' => $sessionId,
            'api_client_id' => $clientId,
            'step_id' => $stepId,
            'request_log_id' => $requestLogId,
        ]);
    }

    /**
     * Get ordered session history with related request and response logs.
     *
     * @return Collection<int, SessionHistory>
     */
    public function getHistory(string $sessionId, int $clientId): Collection
    {
        return SessionHistory::with('requestLog.responseLog')
            ->where('session_id', $sessionId)
            ->where('api_client_id', $clientId)
            ->orderBy('step_id')
            ->get();
    }
}
