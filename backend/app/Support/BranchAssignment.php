<?php

namespace App\Support;

use App\Models\OperatingBranch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The branch a new branch-keyed record is filed under (vehicles and their
 * stock locations, documents, maintenance orders and inspections, employee
 * documents and leaves, sales leads).
 *
 * OperatingBranchScope shows those rows only through their branch, so a row
 * written with no branch is invisible to everyone but platform admins — which
 * is what happened whenever a company-wide user (no branch, e.g. the owner)
 * created one. This class never returns null:
 *
 *  1. the branch of the record it hangs off (the employee, the vehicle, the
 *     lead's owner), when that has one;
 *  2. a branch-bound user's own branch (an owner may still pick another branch
 *     of the company);
 *  3. the branch chosen in the form, validated to the company;
 *  4. the company's only active branch;
 *  otherwise a validation error asking for the branch — the form then offers
 *  the select (see choices()).
 */
final class BranchAssignment
{
    public const FIELD = 'operating_branch_id';

    public function resolve(User $actor, ?int $requested = null, ?int $inherited = null, ?int $companyId = null): int
    {
        if ($inherited !== null) {
            return $inherited;
        }

        $companyId ??= $actor->operating_company_id === null ? null : (int) $actor->operating_company_id;
        $mayChoose = $actor->operating_branch_id === null || $actor->isOwner() || $actor->isPlatformAdmin();

        if ($requested !== null && $mayChoose) {
            return $this->validated($actor, $requested, $companyId)->id;
        }

        if ($actor->operating_branch_id !== null) {
            return (int) $actor->operating_branch_id;
        }

        $only = $this->activeBranches($companyId);
        if ($companyId !== null && $only->count() === 1) {
            return $only->first()->id;
        }

        throw ValidationException::withMessages([
            self::FIELD => 'اختر الفرع الذي يتبع له هذا السجل.',
        ]);
    }

    /**
     * Branches the form should offer: only for a company-wide user whose
     * company has more than one active branch (with one, it is used
     * automatically; a branch-bound user always files under their own).
     *
     * @return Collection<int, OperatingBranch>
     */
    public function choices(User $actor, ?int $companyId = null): Collection
    {
        if ($actor->operating_branch_id !== null && ! $actor->isPlatformAdmin()) {
            return collect();
        }

        $branches = $this->activeBranches($companyId ?? $actor->operating_company_id, $actor->isPlatformAdmin());

        return $branches->count() > 1 ? $branches : collect();
    }

    /** The sensible preselection: the actor's own branch, else the company's first (head-office) branch. */
    public function defaultChoice(User $actor, Collection $choices): ?int
    {
        $own = $actor->operating_branch_id === null ? null : (int) $actor->operating_branch_id;

        return $choices->firstWhere('id', $own)?->id ?? $choices->first()?->id;
    }

    private function validated(User $actor, int $branchId, ?int $companyId): OperatingBranch
    {
        $branch = OperatingBranch::query()->whereKey($branchId)->where('is_active', true)->first();
        $allowed = $branch !== null
            && ($actor->isPlatformAdmin()
                ? ($companyId === null || (int) $branch->operating_company_id === $companyId)
                : ((int) $branch->operating_company_id === (int) $actor->operating_company_id
                    && ($companyId === null || (int) $branch->operating_company_id === $companyId)));

        if (! $allowed) {
            throw ValidationException::withMessages([self::FIELD => 'الفرع المختار لا يتبع هذه الشركة.']);
        }

        return $branch;
    }

    /** @return Collection<int, OperatingBranch> */
    private function activeBranches(?int $companyId, bool $anyCompany = false): Collection
    {
        if ($companyId === null && ! $anyCompany) {
            return collect();
        }

        return OperatingBranch::query()->with('company')->where('is_active', true)
            ->when($companyId !== null, fn ($query) => $query->where('operating_company_id', $companyId))
            ->orderBy('id')->get();
    }
}
