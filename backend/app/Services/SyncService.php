<?php

namespace App\Services;

use App\Exceptions\InvalidSyncPayload;
use App\Exceptions\InvalidVisitTransition;
use App\Exceptions\VisitCloseBlocked;
use App\Models\Asset;
use App\Models\ChecklistInstance;
use App\Models\Device;
use App\Models\MediaFile;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\Visit;
use App\Models\VisitEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Offline sync. This is the highest-risk component in the system and the one the
 * paid programmer-gate week is designed to test.
 *
 * Guarantees:
 *  - Replay-safe: the same client_event_id submitted N times produces exactly one
 *    event row and one set of side effects. Enforced by a unique index, not by a
 *    prior SELECT (which races).
 *  - Partial failure is contained: one bad event does not reject the batch. Every
 *    event returns its own status.
 *  - Causal order: events are replayed in per-device sequence order, because the
 *    wall clock can be changed but the sequence cannot.
 *  - Nothing is dropped silently. A rejected event returns a reason the app shows.
 *  - Administrative changes (cancelling a visit, reassigning a technician) are
 *    server-authoritative and are never overwritten by a stale offline event.
 */
class SyncService
{
    public const MAX_PAYLOAD_BYTES = 32768;

    public const EVENT_TYPES = [
        'visit.transition', 'checklist.upsert', 'parts.declaration',
        'part.issue', 'part.return', 'media.register', 'signature.captured',
        'site.scanned', 'asset.scanned', 'geofence.ping', 'note.added',
    ];

    public function __construct(
        private readonly ClockGuard $clockGuard,
        private readonly VisitStateMachine $stateMachine,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array{server_time:string, results:array<int, array<string, mixed>>, visits:array<int, array<string, mixed>>}
     */
    public function ingest(Device $device, array $events, ?string $lastTrustedServerTime = null): array
    {
        $results = [];
        $touchedVisits = [];
        $lastDivergence = null;

        $ordered = $this->clockGuard->orderForReplay($events);

        foreach ($ordered as $raw) {
            $clientEventId = $raw['client_event_id'] ?? null;

            if (! is_string($clientEventId) || $clientEventId === '') {
                $results[] = [
                    'client_event_id' => $clientEventId,
                    'status' => 'rejected',
                    'code' => 'MISSING_CLIENT_EVENT_ID',
                    'message' => 'Every event must carry a device-generated client_event_id.',
                ];

                continue;
            }

            if (VisitEvent::where('client_event_id', $clientEventId)->exists()) {
                $results[] = ['client_event_id' => $clientEventId, 'status' => 'duplicate'];

                continue;
            }

            $clock = $this->clockGuard->evaluate(
                $this->toDate($raw['device_timestamp'] ?? null),
                $this->toDate($raw['last_trusted_server_time'] ?? $lastTrustedServerTime),
                isset($raw['monotonic_offset_ms']) ? (int) $raw['monotonic_offset_ms'] : null,
            );
            $lastDivergence = $clock['divergence'] ?? $lastDivergence;

            try {
                $outcome = DB::transaction(fn () => $this->apply($device, $raw, $clock));

                $results[] = array_merge(
                    ['client_event_id' => $clientEventId, 'status' => 'accepted'],
                    $outcome['meta'] ?? [],
                );

                if (isset($outcome['visit_id'])) {
                    $touchedVisits[$outcome['visit_id']] = true;
                }
            } catch (QueryException $e) {
                // Unique violation = a concurrent replay won the race. Same outcome.
                if ($this->isUniqueViolation($e)) {
                    $results[] = ['client_event_id' => $clientEventId, 'status' => 'duplicate'];

                    continue;
                }

                Log::error('sync.query_failed', ['event' => $clientEventId, 'error' => $e->getMessage()]);
                $results[] = $this->rejection($clientEventId, 'DB_ERROR', 'Storage rejected the event.');
            } catch (InvalidVisitTransition $e) {
                $results[] = $this->rejection($clientEventId, 'INVALID_VISIT_TRANSITION', $e->getMessage(), [
                    'from' => $e->fromState, 'to' => $e->toState, 'allowed' => $e->allowed,
                ]);
            } catch (InvalidSyncPayload $e) {
                $results[] = $this->rejection(
                    $clientEventId,
                    'INVALID_EVENT_PAYLOAD',
                    $e->getMessage(),
                );
            } catch (VisitCloseBlocked $e) {
                // The state machine writes the blockers after ITS transaction, but
                // that write is still inside THIS one, which is now rolling back.
                // Writing again here — outside both — is what actually survives.
                $this->persistBlockers($raw['visit_id'] ?? null, $e->blockers);

                $results[] = $this->rejection($clientEventId, 'VISIT_CLOSE_BLOCKED', $e->getMessage(), [
                    'blockers' => $e->blockers,
                    // Only refusals that clear THEMSELVES are retryable — an
                    // upload still in flight will land on its own. Marking every
                    // blocker retryable meant a missing signature or an unopened
                    // checklist looped forever: nothing would ever change without
                    // the technician acting, and nothing told them to act.
                    'retryable' => self::blockersAreTransient($e->blockers),
                ]);
            } catch (Throwable $e) {
                Log::warning('sync.event_failed', ['event' => $clientEventId, 'error' => $e->getMessage()]);
                $results[] = $this->rejection($clientEventId, 'EVENT_FAILED', $e->getMessage());
            }
        }

        $this->clockGuard->rememberSkew($device, $lastDivergence);

        return [
            'server_time' => CarbonImmutable::now()->toIso8601String(),
            'results' => $results,
            'visits' => $this->canonicalVisits(array_keys($touchedVisits)),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array{divergence: ?int, suspect: bool}  $clock
     * @return array{visit_id?:int, meta?:array<string, mixed>}
     */
    private function apply(Device $device, array $raw, array $clock): array
    {
        $visit = Visit::findOrFail($raw['visit_id']);

        // A technician's offline event must never resurrect a visit the office
        // reassigned or cancelled while the device was dark.
        // Explicit match, not "not someone else's".
        //
        // The old condition let a NULL assignment through, so any technician who
        // knew a visit id could edit it, close it and issue stock against it —
        // /sync/events never consults VisitPolicy, so this is the only gate.
        if ($visit->assigned_user_id !== $device->user_id) {
            throw new \RuntimeException(
                $visit->assigned_user_id === null
                    ? 'This visit is not assigned to you. Ask the supervisor to assign it.'
                    : 'This visit is no longer assigned to this technician.'
            );
        }

        $type = (string) $raw['event_type'];
        $payload = $this->validatedPayload($visit, $type, $raw['payload'] ?? []);
        $raw['payload'] = $payload;

        // Validation happens before the audit event is stored. Rejected data must
        // not look like a successfully synced business event in the immutable log.
        $event = $this->storeEvent($device, $visit, $raw, $clock);
        $meta = [];

        switch ($type) {
            case 'visit.transition':
                $this->stateMachine->transition($visit, (string) $payload['to'], [
                    'device_id' => $device->id,
                    'actor_user_id' => $device->user_id,
                    'device_timestamp' => $event->device_timestamp,
                    'lat' => $raw['lat'] ?? null,
                    'lng' => $raw['lng'] ?? null,
                    'source' => $raw['source'] ?? 'offline',
                    // A DERIVED uuid, not the parent's with text appended.
                    // `client_event_id` is a real uuid column: PostgreSQL rejects
                    // "…:state" outright, so every offline transition failed on
                    // the first production write while SQLite — which accepts any
                    // string in a uuid column — kept the suite green.
                    'client_event_id' => self::derivedEventId($raw['client_event_id'], 'state'),
                ]);
                $meta['state'] = $visit->refresh()->state;
                break;

            case 'checklist.upsert':
                $instance = ChecklistInstance::updateOrCreate(
                    ['visit_id' => $visit->id, 'asset_id' => (int) $payload['asset_id']],
                    [
                        'client_generated_uuid' => $payload['client_generated_uuid'] ?? null,
                        'status' => $payload['status'] ?? null,
                        'items' => $payload['items'] ?? null,
                        'note' => $payload['note'] ?? null,
                        'no_parts_used' => (bool) ($payload['no_parts_used'] ?? false),
                        'completed_at' => isset($payload['status']) ? CarbonImmutable::now() : null,
                    ],
                );
                $meta['checklist_instance_id'] = $instance->id;
                break;

            case 'parts.declaration':
                // An explicit, visit-level statement by the technician. It used to
                // be inferred from asset status == 'ok', which both blocked a
                // faulty asset from ever closing AND silently asserted "no parts"
                // for a healthy asset whose part had just been replaced.
                ChecklistInstance::where('visit_id', $visit->id)
                    ->update(['no_parts_used' => (bool) ($payload['no_parts_used'] ?? false)]);
                $meta['no_parts_used'] = (bool) ($payload['no_parts_used'] ?? false);
                break;

            case 'part.issue':
                // A technician draws from THEIR OWN vehicle. Trusting the id the
                // device sent let any device issue from the central warehouse or
                // from another technician's van.
                $this->assertOwnVehicleStock($device, (int) $payload['from_location_id']);

                $move = $this->inventory->issueToVisit(
                    (string) $payload['idempotency_key'],
                    (int) $payload['part_id'],
                    (float) $payload['qty'],
                    (int) $payload['from_location_id'],
                    $visit,
                    [
                        'user_id' => $device->user_id,
                        'device_id' => $device->id,
                        'device_timestamp' => $event->device_timestamp,
                    ],
                );
                $meta['stock_move_id'] = $move->id;
                break;

            case 'part.return':
                $original = StockMove::findOrFail((int) $payload['original_move_id']);

                // The return must belong to THIS visit, and land back in the
                // technician's own vehicle. Without both checks a device could
                // reverse any movement in the company into any location.
                if ($original->visit_id !== $visit->id) {
                    throw new \RuntimeException('That part was not issued on this visit.');
                }

                // The quantity guard lives inside InventoryService's locked
                // transaction — checking it here would race two returns.
                $this->assertOwnVehicleStock($device, (int) $payload['to_location_id']);

                $move = $this->inventory->returnFromVisit(
                    (string) $payload['idempotency_key'],
                    $original,
                    (float) $payload['qty'],
                    (int) $payload['to_location_id'],
                    [
                        'user_id' => $device->user_id,
                        'device_id' => $device->id,
                        'device_timestamp' => $event->device_timestamp,
                    ],
                );
                $meta['stock_move_id'] = $move->id;
                break;

            case 'media.register':
                // The app names the ASSET, never a server-side checklist id it has
                // no way of knowing. Bind the photo to that asset, and attach the
                // checklist instance when one exists — creating it if the photo
                // arrived before the checklist event, which an offline batch does.
                $assetId = isset($payload['asset_id']) ? (int) $payload['asset_id'] : null;
                $instanceId = $payload['checklist_instance_id'] ?? null;

                if ($instanceId === null && $assetId !== null) {
                    $instanceId = ChecklistInstance::firstOrCreate(
                        ['visit_id' => $visit->id, 'asset_id' => $assetId],
                    )->id;
                }

                $media = MediaFile::firstOrCreate(
                    ['client_media_id' => (string) $payload['client_media_id']],
                    [
                        'visit_id' => $visit->id,
                        'checklist_instance_id' => $instanceId,
                        'asset_id' => $assetId,
                        'kind' => (string) $payload['kind'],
                        'mime' => $payload['mime'] ?? null,
                        'total_bytes' => $payload['total_bytes'] ?? null,
                        'captured_at' => $this->toDate($payload['captured_at'] ?? null),
                        'lat' => $payload['lat'] ?? null,
                        'lng' => $payload['lng'] ?? null,
                        'declared_source' => $payload['declared_source'] ?? 'camera',
                        'upload_state' => 'pending',
                    ],
                );

                if ($media->visit_id !== $visit->id) {
                    throw new InvalidSyncPayload('client_media_id is already bound to another visit.');
                }

                $meta['media_file_id'] = $media->id;
                $meta['upload_state'] = $media->upload_state;
                $meta['uploaded_bytes'] = $media->uploaded_bytes;
                break;

            default:
                // Validated observational events have no synchronous side effect.
                break;
        }

        return ['visit_id' => $visit->id, 'meta' => $meta];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Visit $visit, string $type, mixed $payload): array
    {
        if (! in_array($type, self::EVENT_TYPES, true)) {
            throw new InvalidSyncPayload('Unsupported event type.');
        }

        if (! is_array($payload)) {
            throw new InvalidSyncPayload('Event payload must be an object.');
        }

        try {
            $payloadBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new InvalidSyncPayload('Event payload must be valid JSON.');
        }

        if ($payloadBytes > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidSyncPayload('Event payload is too large.');
        }

        return match ($type) {
            'visit.transition' => $this->validatedTransitionPayload($payload),
            'checklist.upsert' => $this->validatedChecklistPayload($visit, $payload),
            'parts.declaration' => $this->validatedPartsDeclaration($payload),
            'part.issue' => $this->validatedPartIssuePayload($payload),
            'part.return' => $this->validatedPartReturnPayload($payload),
            'media.register' => $this->validatedMediaPayload($visit, $payload),
            'signature.captured' => $this->validatedSignaturePayload($visit, $payload),
            'site.scanned' => $this->validatedSiteScanPayload($payload),
            'asset.scanned' => $this->validatedAssetScanPayload($visit, $payload),
            'note.added' => ['text' => $this->requiredString($payload, 'text', 4000)],
            'geofence.ping' => isset($payload['index'])
                ? ['index' => $this->requiredInteger($payload, 'index', 0)]
                : [],
        };
    }

    /** @param array<string, mixed> $payload */
    private function validatedTransitionPayload(array $payload): array
    {
        $target = $this->requiredString($payload, 'to', 24);
        $states = array_values(array_unique(array_merge(array_keys(Visit::TRANSITIONS), ...array_values(Visit::TRANSITIONS))));
        if (! in_array($target, $states, true)) {
            throw new InvalidSyncPayload('Unsupported visit state.');
        }

        return ['to' => $target];
    }

    /** @param array<string, mixed> $payload */
    private function validatedChecklistPayload(Visit $visit, array $payload): array
    {
        $assetId = $this->requiredInteger($payload, 'asset_id', 1);
        if (! Asset::whereKey($assetId)->where('site_id', $visit->site_id)->exists()) {
            throw new InvalidSyncPayload('The checklist asset does not belong to this visit site.');
        }

        $status = $this->requiredString($payload, 'status', 24);
        if (! in_array($status, ['ok', 'needs_followup', 'fault'], true)) {
            throw new InvalidSyncPayload('Unsupported checklist status.');
        }

        $items = $payload['items'] ?? null;
        if ($items !== null && (! is_array($items) || count($items) > 100)) {
            throw new InvalidSyncPayload('Checklist items must be an array of at most 100 items.');
        }

        $uuid = $payload['client_generated_uuid'] ?? null;
        if ($uuid !== null && (! is_string($uuid) || ! Uuid::isValid($uuid))) {
            throw new InvalidSyncPayload('client_generated_uuid must be a UUID.');
        }

        $note = $payload['note'] ?? null;
        if ($note !== null && (! is_string($note) || mb_strlen($note) > 2000)) {
            throw new InvalidSyncPayload('Checklist note is too long.');
        }

        return [
            'asset_id' => $assetId,
            'client_generated_uuid' => $uuid,
            'status' => $status,
            'items' => $items,
            'note' => $note,
            'no_parts_used' => $this->booleanValue($payload, 'no_parts_used', false),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatedPartsDeclaration(array $payload): array
    {
        if (! array_key_exists('no_parts_used', $payload)) {
            throw new InvalidSyncPayload('no_parts_used is required.');
        }

        return ['no_parts_used' => $this->booleanValue($payload, 'no_parts_used')];
    }

    /** @param array<string, mixed> $payload */
    private function validatedPartIssuePayload(array $payload): array
    {
        return [
            'part_id' => $this->requiredInteger($payload, 'part_id', 1),
            'qty' => $this->requiredNumber($payload, 'qty', 0.001, 10000),
            'from_location_id' => $this->requiredInteger($payload, 'from_location_id', 1),
            'idempotency_key' => $this->requiredUuid($payload, 'idempotency_key'),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatedPartReturnPayload(array $payload): array
    {
        return [
            'original_move_id' => $this->requiredInteger($payload, 'original_move_id', 1),
            'qty' => $this->requiredNumber($payload, 'qty', 0.001, 10000),
            'to_location_id' => $this->requiredInteger($payload, 'to_location_id', 1),
            'idempotency_key' => $this->requiredUuid($payload, 'idempotency_key'),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatedSignaturePayload(Visit $visit, array $payload): array
    {
        $mediaId = $this->requiredUuid($payload, 'client_media_id');
        if (! MediaFile::where('client_media_id', $mediaId)->where('visit_id', $visit->id)->where('kind', 'signature')->exists()) {
            throw new InvalidSyncPayload('The signature media does not belong to this visit.');
        }

        return [
            'client_media_id' => $mediaId,
            'signer_name' => $this->requiredString($payload, 'signer_name', 190),
            'signer_role' => $this->requiredString($payload, 'signer_role', 120),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatedSiteScanPayload(array $payload): array
    {
        $qrCode = $this->requiredString($payload, 'qr_code', 190);
        if (! str_starts_with($qrCode, 'SITE-')) {
            throw new InvalidSyncPayload('Invalid site QR code.');
        }

        return ['qr_code' => $qrCode];
    }

    /** @param array<string, mixed> $payload */
    private function validatedAssetScanPayload(Visit $visit, array $payload): array
    {
        $assetId = $this->requiredInteger($payload, 'asset_id', 1);
        $qrCode = $this->requiredString($payload, 'qr_code', 190);
        if (! Asset::whereKey($assetId)->where('site_id', $visit->site_id)->where('qr_code', $qrCode)->exists()) {
            throw new InvalidSyncPayload('The scanned asset does not match this visit site.');
        }

        return ['asset_id' => $assetId, 'qr_code' => $qrCode];
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $max): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidSyncPayload("{$key} must be a non-empty string of at most {$max} characters.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $payload */
    private function requiredInteger(array $payload, string $key, int $min): int
    {
        $value = filter_var($payload[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min) {
            throw new InvalidSyncPayload("{$key} must be an integer of at least {$min}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredNumber(array $payload, string $key, float $min, float $max): float
    {
        $value = $payload[$key] ?? null;
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new InvalidSyncPayload("{$key} is outside its permitted range.");
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredUuid(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || ! Uuid::isValid($value)) {
            throw new InvalidSyncPayload("{$key} must be a UUID.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function booleanValue(array $payload, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $payload)) {
            return $default;
        }

        $value = filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidSyncPayload("{$key} must be a boolean.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatedMediaPayload(Visit $visit, array $payload): array
    {
        $clientMediaId = $payload['client_media_id'] ?? null;
        $kind = $payload['kind'] ?? null;
        $mime = $payload['mime'] ?? null;
        $totalBytes = $payload['total_bytes'] ?? null;
        $maxBytes = (int) config('darak.max_media_bytes', 25 * 1024 * 1024);

        if (! is_string($clientMediaId) || ! Uuid::isValid($clientMediaId)) {
            throw new InvalidSyncPayload('client_media_id must be a UUID.');
        }

        if (! in_array($kind, ['photo_before', 'photo_after', 'signature'], true)) {
            throw new InvalidSyncPayload('Unsupported evidence kind.');
        }

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidSyncPayload('Unsupported evidence MIME type.');
        }

        if (! is_int($totalBytes) || $totalBytes < 1 || $totalBytes > $maxBytes) {
            throw new InvalidSyncPayload("Evidence size must be between 1 and {$maxBytes} bytes.");
        }

        if ($kind === 'signature' && $mime !== 'image/png') {
            throw new InvalidSyncPayload('A signature must be registered as image/png.');
        }

        if ($kind !== 'signature' && ! str_starts_with($mime, 'image/')) {
            throw new InvalidSyncPayload('A photo must use an image MIME type.');
        }

        $source = $payload['declared_source'] ?? 'camera';

        if (! in_array($source, ['camera', 'on_screen'], true)) {
            throw new InvalidSyncPayload('Unsupported evidence source.');
        }

        if (isset($payload['asset_id'])) {
            $assetId = filter_var($payload['asset_id'], FILTER_VALIDATE_INT);

            if ($assetId === false || ! Asset::whereKey($assetId)->where('site_id', $visit->site_id)->exists()) {
                throw new InvalidSyncPayload('The evidence asset does not belong to this visit site.');
            }

            $payload['asset_id'] = $assetId;
        }

        if (isset($payload['checklist_instance_id'])) {
            $instanceId = filter_var($payload['checklist_instance_id'], FILTER_VALIDATE_INT);

            if ($instanceId === false || ! ChecklistInstance::whereKey($instanceId)
                ->where('visit_id', $visit->id)->exists()) {
                throw new InvalidSyncPayload('The checklist instance does not belong to this visit.');
            }

            $payload['checklist_instance_id'] = $instanceId;
        }

        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $field => [$min, $max]) {
            if (isset($payload[$field])
                && (! is_numeric($payload[$field]) || (float) $payload[$field] < $min || (float) $payload[$field] > $max)) {
                throw new InvalidSyncPayload("{$field} is outside its valid range.");
            }
        }

        return array_intersect_key($payload, array_flip([
            'client_media_id', 'kind', 'mime', 'total_bytes', 'captured_at',
            'lat', 'lng', 'declared_source', 'asset_id', 'checklist_instance_id',
        ]));
    }

    private function storeEvent(Device $device, Visit $visit, array $raw, array $clock): VisitEvent
    {
        return VisitEvent::create([
            'visit_id' => $visit->id,
            'client_event_id' => (string) $raw['client_event_id'],
            'event_type' => (string) $raw['event_type'],
            'payload' => $raw['payload'] ?? null,
            'device_id' => $device->id,
            'actor_user_id' => $device->user_id,
            'device_timestamp' => $this->toDate($raw['device_timestamp'] ?? null),
            'monotonic_offset_ms' => $raw['monotonic_offset_ms'] ?? null,
            'last_trusted_server_time' => $this->toDate($raw['last_trusted_server_time'] ?? null),
            'server_received_at' => CarbonImmutable::now(),
            'clock_divergence_seconds' => $clock['divergence'],
            'clock_suspect' => $clock['suspect'],
            'lat' => $raw['lat'] ?? null,
            'lng' => $raw['lng'] ?? null,
            'source' => $raw['source'] ?? 'offline',
            'integrity_hash' => $raw['integrity_hash'] ?? null,
            'sequence' => $raw['sequence'] ?? null,
            'sync_status' => 'synced',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function canonicalVisits(array $visitIds): array
    {
        if ($visitIds === []) {
            return [];
        }

        return Visit::with(['checklistInstances', 'mediaFiles', 'stockMoves'])
            ->whereIn('id', $visitIds)
            ->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'state' => $v->state,
                'on_site_seconds' => $v->on_site_seconds,
                'is_rework' => $v->is_rework,
                'close_blockers' => $v->close_blockers,
                'checklist_count' => $v->checklistInstances->count(),
                'media_count' => $v->mediaFiles->count(),
                'parts_count' => $v->stockMoves->count(),
                'server_updated_at' => $v->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** The stock location must be the vehicle assigned to this device's user. */
    private function assertOwnVehicleStock(Device $device, int $locationId): void
    {
        $owns = StockLocation::where('id', $locationId)
            ->where('type', StockLocation::TYPE_VEHICLE)
            ->whereHas('vehicle', fn ($q) => $q->where('assigned_user_id', $device->user_id))
            ->exists();

        if (! $owns) {
            throw new \RuntimeException('You can only move parts through your own vehicle stock.');
        }
    }

    /**
     * True only when every blocker resolves without the technician doing anything.
     *
     * @param  array<int, array<string, mixed>>  $blockers
     */
    public static function blockersAreTransient(array $blockers): bool
    {
        if ($blockers === []) {
            return false;
        }

        // An upload in flight lands by itself. Everything else — a missing
        // signature, an unopened checklist, an upload that has given up — needs
        // the technician, and retrying it silently forever tells them nothing.
        $transient = [
            'UPLOADS_PENDING',
            'ASSET_PHOTO_UPLOAD_PENDING',
            'SIGNATURE_UPLOAD_PENDING',
        ];

        foreach ($blockers as $blocker) {
            if (! in_array($blocker['code'] ?? null, $transient, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A deterministic child id for an event derived from another.
     *
     * UUIDv5 keeps two properties at once: it is a valid uuid the column accepts,
     * and it is stable, so replaying the parent event produces the same child and
     * the unique index still does the deduplication.
     */
    public static function derivedEventId(string $parentId, string $purpose): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_OID, $parentId.':'.$purpose);
    }

    /** @param array<int, array<string, mixed>> $blockers */
    private function persistBlockers(mixed $visitId, array $blockers): void
    {
        if ($visitId === null) {
            return;
        }

        Visit::where('id', $visitId)->update(['close_blockers' => json_encode($blockers)]);
    }

    private function rejection(string $id, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'client_event_id' => $id,
            'status' => 'rejected',
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }

    private function toDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
