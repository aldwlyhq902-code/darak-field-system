<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\Visit;

/**
 * The conditional close gate (CTL-04 in v1.1, carried into v1.2 §2).
 *
 * A visit may only complete when every scheduled asset has been inspected, evidence
 * exists, parts are either recorded or explicitly declared as none, and the site
 * representative has signed. This is the control that makes a remote-supervised
 * two-shift operation possible at all.
 */
class CloseGate
{
    /**
     * @return array<int, array{code:string, message_ar:string, message_en:string, ref?:mixed}>
     */
    public function blockers(Visit $visit): array
    {
        $blockers = [];

        $visit->loadMissing(['checklistInstances.asset', 'mediaFiles', 'stockMoves', 'site.assets']);

        $instances = $visit->checklistInstances;

        if ($instances->isEmpty()) {
            $blockers[] = [
                'code' => 'NO_CHECKLIST',
                'message_ar' => 'لم تُفتح قائمة فحص لأي أصل في هذه الزيارة.',
                'message_en' => 'No asset checklist was opened for this visit.',
            ];
        }

        // Judge the visit against what it was scheduled to cover, not against the
        // subset the client chose to open. Otherwise inspecting one unit and
        // skipping five closed a visit the report then presents as a full round.
        foreach ($this->missingRequiredAssets($visit, $instances) as $asset) {
            $blockers[] = [
                'code' => 'ASSET_NOT_INSPECTED',
                'message_ar' => "لم يُفحص الأصل المطلوب: {$asset['name']}",
                'message_en' => "A required asset was not inspected: {$asset['name']}",
                'ref' => $asset['id'],
            ];
        }

        foreach ($instances as $instance) {
            $assetName = $instance->asset?->name ?? ('#' . $instance->asset_id);

            if (blank($instance->status)) {
                $blockers[] = [
                    'code' => 'ASSET_STATUS_MISSING',
                    'message_ar' => "لم تُسجَّل حالة الأصل: {$assetName}",
                    'message_en' => "No status recorded for asset: {$assetName}",
                    'ref' => $instance->asset_id,
                ];
            }

            // Match on the asset, not the checklist instance: the app names the
            // asset, and an offline batch can deliver the photo before the
            // checklist event that would have created the instance.
            $photos = $visit->mediaFiles
                ->whereIn('kind', ['photo_before', 'photo_after'])
                ->filter(fn ($media) => (int) $media->asset_id === (int) $instance->asset_id
                    || (int) $media->checklist_instance_id === (int) $instance->id);

            $complete = $photos->firstWhere('upload_state', 'complete');
            $inFlight = $photos->first(fn ($m) => in_array($m->upload_state, ['pending', 'uploading'], true));
            $failed = $photos->firstWhere('upload_state', 'failed');

            // "Not there yet" and "not there at all" are different refusals.
            // Collapsing them meant a photo mid-upload produced a permanent
            // blocker, so the client parked the close as failed and the
            // technician had to hunt for it — the exact case the retry exists for.
            if ($complete === null) {
                if ($inFlight !== null) {
                    $blockers[] = [
                        'code' => 'ASSET_PHOTO_UPLOAD_PENDING',
                        'message_ar' => "صورة الأصل «{$assetName}» ما زالت ترفع.",
                        'message_en' => "The photo for asset {$assetName} is still uploading.",
                        'ref' => $instance->asset_id,
                    ];
                } elseif ($failed !== null) {
                    $blockers[] = [
                        'code' => 'ASSET_PHOTO_UPLOAD_FAILED',
                        'message_ar' => "فشل رفع صورة الأصل «{$assetName}» — أعد الالتقاط.",
                        'message_en' => "The photo for asset {$assetName} failed to upload — retake it.",
                        'ref' => $instance->asset_id,
                    ];
                } else {
                    $blockers[] = [
                        'code' => 'ASSET_PHOTO_MISSING',
                        'message_ar' => "لا توجد صورة للأصل: {$assetName}",
                        'message_en' => "No photo for asset: {$assetName}",
                        'ref' => $instance->asset_id,
                    ];
                }
            }
        }

        // Parts must be either recorded or explicitly declared as none. Silence is
        // not an answer — unrecorded parts are how vehicle stock quietly disappears.
        //
        // The declaration is visit-level and deliberate: requiring EVERY asset to
        // carry the flag meant a single faulty unit could never satisfy the gate.
        $hasPartMoves = $visit->stockMoves->isNotEmpty();
        $declaredNoParts = $instances->isNotEmpty() && $instances->contains(fn ($i) => (bool) $i->no_parts_used);

        if (! $hasPartMoves && ! $declaredNoParts) {
            $blockers[] = [
                'code' => 'PARTS_NOT_DECLARED',
                'message_ar' => 'سجّل القطع المستخدمة أو صرّح بأنه لم تُستخدم قطع.',
                'message_en' => 'Record the parts used, or explicitly declare that none were used.',
            ];
        }

        $hasSignature = $visit->mediaFiles
            ->where('kind', 'signature')
            ->where('upload_state', 'complete')
            ->isNotEmpty();

        if (! $hasSignature) {
            $blockers[] = [
                'code' => 'SIGNATURE_MISSING',
                'message_ar' => 'توقيع مسؤول الموقع مطلوب لإقفال الزيارة.',
                'message_en' => 'The site representative signature is required to close the visit.',
            ];
        }

        // Still moving vs given up. An upload that has failed will never finish on
        // its own, so counting it as "pending" left the visit un-closable forever
        // with a message that promised it was about to resolve.
        $inFlightUploads = $visit->mediaFiles->whereIn('upload_state', ['pending', 'uploading'])->count();
        $failedUploads = $visit->mediaFiles->where('upload_state', 'failed')->count();

        if ($failedUploads > 0) {
            $blockers[] = [
                'code' => 'UPLOADS_FAILED',
                'message_ar' => "فشل رفع {$failedUploads} ملف — أعد الالتقاط أو احذفها.",
                'message_en' => "{$failedUploads} upload(s) failed — retake or discard them.",
                'ref' => $failedUploads,
            ];
        }

        if ($inFlightUploads > 0) {
            $blockers[] = [
                'code' => 'UPLOADS_PENDING',
                'message_ar' => "بانتظار اكتمال رفع {$inFlightUploads} ملف.",
                'message_en' => "{$inFlightUploads} file upload(s) still pending.",
                'ref' => $inFlightUploads,
            ];
        }

        return $blockers;
    }

    public function passes(Visit $visit): bool
    {
        return $this->blockers($visit) === [];
    }

    /**
     * Required assets with no checklist instance.
     *
     * Falls back to the site's assets when the snapshot is absent (a visit created
     * before the column existed), so an old row cannot pass by having no
     * requirement at all.
     *
     * @return array<int, array{id:int, name:string}>
     */
    private function missingRequiredAssets(Visit $visit, $instances): array
    {
        $required = $visit->required_asset_ids;

        if ($required === null) {
            $required = $visit->site?->assets->pluck('id')->all() ?? [];
        }

        if ($required === []) {
            return [];
        }

        $inspected = $instances->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
        $names = $visit->site?->assets->keyBy('id') ?? collect();

        $missing = [];

        foreach ($required as $assetId) {
            if (in_array((int) $assetId, $inspected, true)) {
                continue;
            }

            $missing[] = [
                'id' => (int) $assetId,
                'name' => $names[$assetId]->name ?? ('#' . $assetId),
            ];
        }

        return $missing;
    }
}
