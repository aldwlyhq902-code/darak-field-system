{{--
    Branch for a new record. Rendered only when the user works company-wide and
    the company has more than one active branch (App\Support\BranchAssignment::choices);
    otherwise the server files the record under the only possible branch.
    Params: $branchChoices (collection), $branchDefault (?int), $branchHint (?string).
--}}
@if(($branchChoices ?? collect())->count() > 1)
    @php($branchFieldId = 'branch-'.\Illuminate\Support\Str::random(6))
    <div class="field">
        <label for="{{ $branchFieldId }}">الفرع</label>
        <select id="{{ $branchFieldId }}" name="operating_branch_id" required>
            @foreach($branchChoices as $choice)
                <option value="{{ $choice->id }}" @selected((int) old('operating_branch_id', $branchDefault ?? 0) === $choice->id)>{{ $choice->name }}@if(auth()->user()?->isPlatformAdmin()) — {{ $choice->company?->name }}@endif</option>
            @endforeach
        </select>
        @if(!empty($branchHint))<small class="muted">{{ $branchHint }}</small>@endif
    </div>
@endif
