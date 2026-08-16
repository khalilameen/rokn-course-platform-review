<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentReconciliationFindingController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in([
                PaymentReconciliationFinding::STATE_OPEN,
                PaymentReconciliationFinding::STATE_RESOLVED,
                PaymentReconciliationFinding::STATE_IGNORED,
            ])],
            'kind' => 'nullable|string|max:64',
            'order_ref' => 'nullable|string|max:191',
        ]);
        if (!$request->query->has('state')) {
            $filters['state'] = PaymentReconciliationFinding::STATE_OPEN;
        }

        $findings = PaymentReconciliationFinding::query()
            ->with(['order', 'resolver:id,name'])
            ->when(
                $filters['state'] ?? null,
                fn ($query, $state) => $query->where('state', $state)
            )
            ->when(
                $filters['kind'] ?? null,
                fn ($query, $kind) => $query->where('kind', trim($kind))
            )
            ->when(
                $filters['order_ref'] ?? null,
                fn ($query, $orderRef) => $query->where(
                    'order_ref',
                    'like',
                    '%'.addcslashes(trim($orderRef), '%_\\').'%'
                )
            )
            ->orderByRaw("CASE state WHEN 'open' THEN 0 WHEN 'ignored' THEN 1 ELSE 2 END")
            ->latest('last_seen_at')
            ->paginate(30)
            ->withQueryString();

        $stateCounts = PaymentReconciliationFinding::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');
        $kinds = PaymentReconciliationFinding::query()
            ->whereNotNull('kind')
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind');

        return view('admin.payment-reconciliation-findings.index', compact(
            'findings',
            'filters',
            'stateCounts',
            'kinds'
        ));
    }

    public function resolve(
        Request $request,
        PaymentReconciliationFinding $paymentReconciliationFinding
    ): RedirectResponse {
        return $this->transition(
            $request,
            $paymentReconciliationFinding,
            PaymentReconciliationFinding::STATE_RESOLVED,
            'تم تعليم نتيجة التسوية كمحلولة.'
        );
    }

    public function ignore(
        Request $request,
        PaymentReconciliationFinding $paymentReconciliationFinding
    ): RedirectResponse {
        return $this->transition(
            $request,
            $paymentReconciliationFinding,
            PaymentReconciliationFinding::STATE_IGNORED,
            'تم تجاهل نتيجة التسوية مع تسجيل السبب.'
        );
    }

    public function reopen(
        Request $request,
        PaymentReconciliationFinding $paymentReconciliationFinding
    ): RedirectResponse {
        return $this->transition(
            $request,
            $paymentReconciliationFinding,
            PaymentReconciliationFinding::STATE_OPEN,
            'أُعيد فتح نتيجة التسوية للمراجعة.'
        );
    }

    private function transition(
        Request $request,
        PaymentReconciliationFinding $finding,
        string $targetState,
        string $successMessage
    ): RedirectResponse {
        $validated = $request->validate([
            'note' => 'required|string|min:3|max:2000',
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        DB::transaction(function () use ($finding, $targetState, $validated, $actor): void {
            $lockedFinding = PaymentReconciliationFinding::query()
                ->whereKey($finding->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $allowedStates = $targetState === PaymentReconciliationFinding::STATE_OPEN
                ? [
                    PaymentReconciliationFinding::STATE_RESOLVED,
                    PaymentReconciliationFinding::STATE_IGNORED,
                ]
                : [PaymentReconciliationFinding::STATE_OPEN];

            if (!in_array($lockedFinding->state, $allowedStates, true)) {
                throw ValidationException::withMessages([
                    'finding' => 'تغيرت حالة نتيجة التسوية بالفعل. حدّث الصفحة قبل تسجيل قرار جديد.',
                ]);
            }

            $lockedFinding->update([
                'state' => $targetState,
                'resolved_at' => $targetState === PaymentReconciliationFinding::STATE_OPEN
                    ? null
                    : now(),
                'resolved_by' => $targetState === PaymentReconciliationFinding::STATE_OPEN
                    ? null
                    : $actor->getKey(),
                'resolution_note' => trim($validated['note']),
            ]);
        }, 3);

        return back()->with('success', $successMessage);
    }
}
