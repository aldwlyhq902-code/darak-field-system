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

        $visit->loadMissing(['checklistInstances.asset', 'mediaFiles', 'stockMoves']);

        $instances = $visit->checklistInstances;

        if ($instances->isEmpty()) {
            $blockers[] = [
                'code' => 'NO_CHECKLIST',
                'message_ar' => 'لم تُفتح قائمة فحص لأي أصل في هذه الزيارة.',
                'message_en' => 'No asset checklist was opened for this visit.',
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
            $hasPhoto = $visit->mediaFiles
                ->whereIn('kind', ['photo_before', 'photo_after'])
                ->where('upload_state', 'complete')
                ->first(fn ($media) => (int) $media->asset_id === (int) $instance->asset_id
                    || (int) $media->checklist_instance_id === (int) $instance->id) !== null;

            if (! $hasPhoto) {
                $blockers[] = [
                    'code' => 'ASSET_PHOTO_MISSING',
                    'message_ar' => "لا توجد صورة مرفوعة للأصل: {$assetName}",
                    'message_en' => "No uploaded photo for asset: {$assetName}",
                    'ref' => $instance->asset_id,
                ];
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

        $pendingUploads = $visit->mediaFiles->where('upload_state', '!=', 'complete')->count();
        if ($pendingUploads > 0) {
            $blockers[] = [
                'code' => 'UPLOADS_PENDING',
                'message_ar' => "بانتظار اكتمال رفع {$pendingUploads} ملف.",
                'message_en' => "{$pendingUploads} file upload(s) still pending.",
                'ref' => $pendingUploads,
            ];
        }

        return $blockers;
    }

    public function passes(Visit $visit): bool
    {
        return $this->blockers($visit) === [];
    }
}
