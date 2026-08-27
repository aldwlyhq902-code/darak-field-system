<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLeave;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LeaveManagementService;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HumanResourcesController extends Controller
{
    public function __construct(private readonly LeaveManagementService $leaves, private readonly AuditLogger $audit, private readonly TenantAccess $tenantAccess) {}

    public function index(Request $request): View
    {
        $users = User::query()->with(['employeeProfile', 'employeeDocuments' => fn ($query) => $query->where('status', 'active')->latest('expires_on'), 'employeeLeaves' => fn ($query) => $query->latest('starts_on')->limit(10)])
            ->when(! $request->user()->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $request->user()->operating_company_id))
            ->when($request->user()->operating_branch_id, fn ($query, $branchId) => $query->where('operating_branch_id', $branchId))
            ->orderBy('name')->get();

        return view('panel.human-resources', [
            'users' => $users,
            'balances' => $users->mapWithKeys(fn (User $user) => [$user->id => $this->leaves->balance($user)]),
            'expiringDocuments' => EmployeeDocument::with('user')->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(90))->orderBy('expires_on')->get(),
            'pendingLeaves' => EmployeeLeave::with('user')->where('status', 'pending')->orderBy('starts_on')->get(),
            'recentLeaves' => EmployeeLeave::with('user')->latest('id')->limit(30)->get(),
        ]);
    }

    public function profile(Request $request, User $user): RedirectResponse
    {
        $this->authorizeUser($request, $user);
        $employeeNoRule = Rule::unique('employee_profiles', 'employee_no');
        if ($user->employeeProfile) {
            $employeeNoRule->ignore($user->employeeProfile->id);
        }
        $data = $request->validate([
            'employee_no' => ['nullable', 'string', 'max:48', $employeeNoRule],
            'nationality' => ['nullable', 'string', 'max:100'], 'hired_on' => ['nullable', 'date'],
            'annual_leave_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'emergency_contact_name' => ['nullable', 'string', 'max:190'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $profile = $user->employeeProfile ?? new EmployeeProfile(['user_id' => $user->id]);
        $before = $profile->exists ? $profile->getAttributes() : null;
        $profile->fill($data + ['user_id' => $user->id])->save();
        $this->audit->record('employee.profile_saved', $profile, $before, $profile->getAttributes(), $request->user()->id);

        return back()->with('ok', 'حُفظ ملف الموظف.');
    }

    public function document(Request $request, User $user): RedirectResponse
    {
        $this->authorizeUser($request, $user);
        $data = $request->validate([
            'type' => ['required', 'in:iqama,passport,work_permit,medical_insurance,contract,other'],
            'document_number' => ['nullable', 'string', 'max:96'], 'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'], 'issuer' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $file = $request->file('file');
        $path = $file?->store('employee-documents/'.$user->id, 'local');
        unset($data['file']);

        try {
            EmployeeDocument::where('user_id', $user->id)->where('type', $data['type'])->where('status', 'active')->update(['status' => 'superseded']);
            $document = EmployeeDocument::create($data + [
                'user_id' => $user->id, 'operating_branch_id' => $user->operating_branch_id,
                'file_path' => $path ?: null, 'file_name' => $file?->getClientOriginalName(),
                'mime_type' => $file?->getMimeType(), 'file_size' => $file?->getSize(),
                'status' => 'active', 'created_by' => $request->user()->id,
            ]);
            $this->audit->record('employee.document_added', $document, null, $document->only(['user_id', 'type', 'expires_on', 'status']), $request->user()->id);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('ok', 'حُفظت وثيقة الموظف وأصبح الإصدار السابق مؤرشفًا.');
    }

    public function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $this->authorizeUser($request, $document->user);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name ?? basename($document->file_path), ['X-Content-Type-Options' => 'nosniff']);
    }

    public function leave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'], 'type' => ['required', 'in:annual,sick,unpaid,emergency,other'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = User::findOrFail($data['user_id']);
        $this->authorizeUser($request, $user);
        $overlap = EmployeeLeave::where('user_id', $user->id)->whereIn('status', ['pending', 'approved'])
            ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists();
        if ($overlap) {
            return back()->with('err', 'يوجد طلب إجازة متداخل لهذا الموظف.');
        }
        $days = $this->leaves->days($data['starts_on'], $data['ends_on']);
        if ($data['type'] === 'annual' && $days > $this->leaves->balance($user)['remaining']) {
            return back()->with('err', 'مدة الإجازة السنوية تتجاوز الرصيد المتاح.');
        }
        $leave = EmployeeLeave::create($data + [
            'operating_branch_id' => $user->operating_branch_id, 'days' => $days,
            'status' => 'pending', 'requested_by' => $request->user()->id,
        ]);
        $this->audit->record('employee.leave_requested', $leave, null, $leave->only(['user_id', 'type', 'starts_on', 'ends_on', 'days']), $request->user()->id);

        return back()->with('ok', 'سُجل طلب الإجازة وبانتظار القرار.');
    }

    public function leaveDecision(Request $request, EmployeeLeave $leave): RedirectResponse
    {
        $this->authorizeUser($request, $leave->user);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'response_note' => ['nullable', 'string', 'max:2000']]);
        $before = $leave->getAttributes();
        try {
            if ($data['decision'] === 'approve') {
                $reassigned = $this->leaves->approve($leave, $request->user());
                $message = "اعتمدت الإجازة وأُعيد توزيع {$reassigned} زيارة متأثرة.";
            } else {
                $this->leaves->reject($leave, $request->user(), $data['response_note'] ?? 'رُفض الطلب.');
                $message = 'رُفض طلب الإجازة مع حفظ الملاحظة.';
            }
        } catch (RuntimeException $exception) {
            return back()->with('err', $exception->getMessage());
        }
        $this->audit->recordChange('employee.leave_decided', $leave->refresh(), $before, $request->user()->id);

        return back()->with('ok', $message);
    }

    private function authorizeUser(Request $request, User $user): void
    {
        $actor = $request->user();
        $this->tenantAccess->assertUser($actor, $user);
        abort_unless($actor->isPlatformAdmin() || $actor->isOwner() || $actor->operating_branch_id === null || $actor->operating_branch_id === $user->operating_branch_id, 403);
    }
}
