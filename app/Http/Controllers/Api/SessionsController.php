<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Components\Sessions\Contracts\SessionsContract;
use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\DTO\SessionSendMessageInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\CreateSessionRequest;
use App\Http\Requests\Sessions\PaginateHistoryRequest;
use App\Http\Requests\Sessions\SendSessionMessageRequest;
use App\Http\Resources\Sessions\SessionHistoryPageResource;
use App\Http\Resources\Sessions\SessionMetadataResource;
use App\Http\Resources\Sessions\SessionSendMessageResource;
use App\Models\Session;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SessionsController extends Controller
{
    public function __construct(
        private readonly SessionsContract $sessions,
    ) {}

    public function create(CreateSessionRequest $request): JsonResponse
    {
        $client = $request->attributes->get('auth.client');
        $validated = $request->validated();

        $input = new SessionCreateInput(
            clientId: (int) $client->id,
            workspaceId: $client->workspace_id ? (int) $client->workspace_id : null,
            modelAlias: $validated['model_alias'],
            system: $validated['system'] ?? null,
            tools: $validated['tools'] ?? [],
            cacheStrategy: $validated['cache_strategy'] ?? null,
            contextManagement: $validated['context_management'] ?? [],
            autoResume: $validated['auto_resume'] ?? false,
            expiresAt: isset($validated['expires_at']) ? new DateTimeImmutable($validated['expires_at']) : null,
        );

        $metadata = $this->sessions->create($input);

        return (new SessionMetadataResource($metadata))->response()->setStatusCode(201);
    }

    public function show(Session $session): SessionMetadataResource
    {
        return new SessionMetadataResource($this->sessions->getMetadata($session->session_id));
    }

    public function destroy(Session $session): JsonResponse
    {
        $this->sessions->delete($session->session_id);

        return response()->json(null, 204);
    }

    public function history(Session $session, PaginateHistoryRequest $request): SessionHistoryPageResource
    {
        $from = (int) $request->validated('from', 0);
        $limit = (int) $request->validated('limit', 50);

        return new SessionHistoryPageResource($this->sessions->paginateHistory($session->session_id, $from, $limit));
    }

    public function send(Session $session, SendSessionMessageRequest $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validated();
        $messages = $validated['messages'];
        $userContent = $messages[0]['content'] ?? '';

        $input = new SessionSendMessageInput(
            newUserContent: is_array($userContent) ? $userContent : [['type' => 'text', 'text' => $userContent]],
            maxTokens: $validated['max_tokens'] ?? null,
            stream: $validated['stream'] ?? false,
        );

        try {
            if ($input->stream) {
                $generator = $this->sessions->sendStream($session->session_id, $input);

                return new StreamedResponse(function () use ($generator) {
                    foreach ($generator as $frame) {
                        echo $frame;
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }, 200, [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache, no-store',
                    'X-Accel-Buffering' => 'no',
                    'Connection' => 'keep-alive',
                ]);
            }

            $result = $this->sessions->sendSync($session->session_id, $input);
        } catch (RateLimitExceededException $e) {
            return new JsonResponse(
                [
                    'type' => 'error',
                    'error' => [
                        'type' => 'rate_limit_error',
                        'message' => 'Rate limit pre-emptively exceeded on axis: '.$e->axis,
                    ],
                ],
                429,
                ['Retry-After' => (string) $e->retryAfterSeconds],
            );
        }

        $response = (new SessionSendMessageResource($result))->response();

        if (in_array('auto_resume_limit_reached', $result->warnings, true)) {
            $response->header('X-Gateway-Warning', 'auto_resume_limit_reached');
        }

        return $response;
    }
}
