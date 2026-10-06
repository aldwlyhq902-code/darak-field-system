<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key for mutating, non-sync API calls (contract C2).
 *
 * A phone on a bad link often loses the RESPONSE to "accept this custody" or
 * "release this transfer" and retries. Without a key the retry is a second
 * action. With one:
 *  - first request: claimed atomically (unique user+key), executed, and a 2xx
 *    response remembered for 48 h;
 *  - same key, same method/path/body: the stored response is returned with
 *    `Idempotent-Replayed: true` and nothing runs again;
 *  - same key, different request: 422 IDEMPOTENCY_KEY_REUSED;
 *  - same key while the first is still running: 409 IDEMPOTENCY_REQUEST_IN_PROGRESS;
 *  - no header: processed normally (older app builds keep working).
 * Failures (non-2xx) are not remembered, so a corrected retry can succeed.
 */
class EnsureIdempotency
{
    /** A claim older than this with no outcome belongs to a dead worker. */
    private const ABANDONED_AFTER_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();

        if ($key === '' || $user === null) {
            return $next($request);
        }

        if (! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/', $key)) {
            return response()->json([
                'code' => 'IDEMPOTENCY_KEY_INVALID',
                'message' => 'Idempotency-Key must be 8-128 letters, digits, "-" or "_" (a UUID v4 is expected).',
            ], 422);
        }

        $method = $request->method();
        $path = '/'.ltrim($request->path(), '/').($request->getQueryString() !== null ? '?'.$request->getQueryString() : '');
        $fingerprint = $this->fingerprint($request, $method, $path);

        $claim = $this->claim((int) $user->getAuthIdentifier(), $key, $method, $path, $fingerprint);

        if ($claim instanceof Response) {
            return $claim;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $claim->delete();

            throw $e;
        }

        $body = $response->getContent();

        if (! $response->isSuccessful() || $body === false) {
            $claim->delete();

            return $response;
        }

        $claim->forceFill([
            'state' => IdempotencyKey::STATE_COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => $body,
            'response_content_type' => mb_substr((string) $response->headers->get('Content-Type', 'application/json'), 0, 128),
        ])->save();

        return $response;
    }

    private function claim(int $userId, string $key, string $method, string $path, string $fingerprint, bool $retry = true): IdempotencyKey|Response
    {
        $now = now();

        // ON CONFLICT DO NOTHING: exactly one concurrent request wins the row.
        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'user_id' => $userId,
            'idempotency_key' => $key,
            'method' => $method,
            'path' => mb_substr($path, 0, 512),
            'request_hash' => $fingerprint,
            'state' => IdempotencyKey::STATE_PROCESSING,
            'expires_at' => $now->copy()->addHours(max(1, (int) config('darak.api.idempotency_ttl_hours', 48))),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = IdempotencyKey::query()->where('user_id', $userId)->where('idempotency_key', $key)->first();

        if ($inserted === 1 && $record !== null) {
            return $record;
        }

        if ($record === null
            || $record->expires_at->isPast()
            || ($record->state === IdempotencyKey::STATE_PROCESSING
                && $record->updated_at->lt($now->copy()->subSeconds(self::ABANDONED_AFTER_SECONDS)))) {
            if ($retry) {
                // Expired or abandoned: remove exactly that row and claim afresh.
                $record?->delete();

                return $this->claim($userId, $key, $method, $path, $fingerprint, false);
            }

            return $this->inProgress();
        }

        if ($record->method !== $method || $record->path !== mb_substr($path, 0, 512)
            || ! hash_equals($record->request_hash, $fingerprint)) {
            return response()->json([
                'code' => 'IDEMPOTENCY_KEY_REUSED',
                'message' => 'This Idempotency-Key was already used for a different request.',
            ], 422);
        }

        if ($record->state !== IdempotencyKey::STATE_COMPLETED) {
            return $this->inProgress();
        }

        return response((string) $record->response_body, (int) $record->response_status)
            ->header('Content-Type', $record->response_content_type ?: 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }

    private function inProgress(): Response
    {
        return response()->json([
            'code' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
            'message' => 'The original request with this Idempotency-Key is still being processed. Retry shortly.',
        ], 409)->header('Retry-After', '2');
    }

    /** Method, path and body. Multipart bodies hash their fields and file bytes. */
    private function fingerprint(Request $request, string $method, string $path): string
    {
        $body = (string) $request->getContent();

        if ($body === '' && ($request->request->count() > 0 || $request->allFiles() !== [])) {
            $fields = $request->request->all();
            $this->sortRecursive($fields);
            $files = [];
            $uploads = $request->allFiles();
            array_walk_recursive(
                $uploads,
                function ($file, $name) use (&$files): void {
                    if ($file instanceof UploadedFile && $file->isValid()) {
                        $files[] = $name.':'.hash_file('sha256', (string) $file->getRealPath());
                    }
                },
            );
            sort($files);
            $body = json_encode(['fields' => $fields, 'files' => $files]) ?: '';
        }

        return hash('sha256', $method."\n".$path."\n".$body);
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
    }
}
