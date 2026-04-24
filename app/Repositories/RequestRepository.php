<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ApiRequest;
use App\Models\RequestRaw;
use App\Models\RequestUsage;
use App\Repositories\DTO\RequestDetails;

final class RequestRepository
{
    public function find(string $requestId): ?ApiRequest
    {
        return ApiRequest::query()->find($requestId);
    }

    public function findForClient(string $requestId, int $clientId): ?ApiRequest
    {
        return ApiRequest::query()
            ->where('request_id', $requestId)
            ->where('client_id', $clientId)
            ->first();
    }

    public function findDetails(string $requestId, bool $includeRaw): RequestDetails
    {
        $request = ApiRequest::query()->find($requestId);
        if ($request === null) {
            return new RequestDetails(null, null, null);
        }

        $usage = RequestUsage::query()->find($requestId);
        $raw = $includeRaw ? RequestRaw::query()->find($requestId) : null;

        return new RequestDetails($request, $usage, $raw);
    }

    public function getStatus(string $requestId): ?string
    {
        $status = ApiRequest::query()->where('request_id', $requestId)->value('status');

        return is_string($status) ? $status : null;
    }

    /**
     * @note Call inside DB::transaction() — this method does not wrap its own.
     */
    public function createAccepted(
        string $requestId,
        int $clientId,
        string $endpoint,
        string $mode,
        string $modelAlias,
        string $modelSnapshot,
        string $status,
    ): void {
        ApiRequest::query()->create([
            'request_id' => $requestId,
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'mode' => $mode,
            'model_alias' => $modelAlias,
            'model_snapshot' => $modelSnapshot,
            'status' => $status,
            'created_at' => now(),
        ]);
    }

    public function markInProgress(string $requestId, string $inProgressStatus): void
    {
        ApiRequest::query()
            ->where('request_id', $requestId)
            ->update([
                'status' => $inProgressStatus,
                'started_at' => now(),
            ]);
    }

    public function markFinalStatus(
        string $requestId,
        string $status,
        ?string $errorType = null,
        ?string $errorMessage = null,
    ): void {
        ApiRequest::query()
            ->where('request_id', $requestId)
            ->update([
                'status' => $status,
                'error_type' => $errorType,
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);
    }

    public function setStatus(string $requestId, string $status): void
    {
        ApiRequest::query()
            ->where('request_id', $requestId)
            ->update(['status' => $status]);
    }
}
