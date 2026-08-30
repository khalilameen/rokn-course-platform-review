<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class OperatingCostPoolController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'service_key' => ['nullable', Rule::in(array_keys(OperatingCostPool::SERVICES))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $poolQuery = OperatingCostPool::query()
            ->with('course')
            ->when($filters['service_key'] ?? null, fn ($query, $service) => $query->where('service_key', $service))
            ->when($filters['course_id'] ?? null, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('period_end', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('period_start', '<=', $to));
        $matchingPools = (clone $poolQuery)->get();
        $pools = $poolQuery->latest('period_end')->paginate(30)->withQueryString();
        $courses = Course::query()
            ->whereNull('parent_id')
            ->withCount('activeEnrollments')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar']);
        $settings = Setting::query()->firstOrCreate([]);
        $editPool = $request->filled('edit_cost')
            ? OperatingCostPool::query()->findOrFail((int) $request->input('edit_cost'))
            : null;

        $totals = [
            'actual_egp' => round((float) $matchingPools->where('is_final', true)->sum(fn (OperatingCostPool $pool) => $pool->amountEgp() ?? 0), 2),
            'estimated_egp' => round((float) $matchingPools->where('is_final', false)->sum(fn (OperatingCostPool $pool) => $pool->amountEgp() ?? 0), 2),
            'missing_fx' => $matchingPools->filter(fn (OperatingCostPool $pool) => $pool->amountEgp() === null)->count(),
        ];
        $serviceSummary = $matchingPools->groupBy('service_key')->map(fn ($servicePools) => [
            'actual_egp' => round((float) $servicePools->where('is_final', true)->sum(fn (OperatingCostPool $pool) => $pool->amountEgp() ?? 0), 2),
            'estimated_egp' => round((float) $servicePools->where('is_final', false)->sum(fn (OperatingCostPool $pool) => $pool->amountEgp() ?? 0), 2),
        ]);

        return view('admin.operating-costs.index', compact(
            'pools', 'courses', 'settings', 'editPool', 'filters', 'totals', 'serviceSummary'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        OperatingCostPool::query()->create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'تمت إضافة فاتورة التكلفة وستدخل في تقارير الربحية.');
    }

    public function update(Request $request, OperatingCostPool $operatingCost): RedirectResponse
    {
        $operatingCost->update($this->validated($request));

        return back()->with('success', 'تم تحديث تكلفة التشغيل.');
    }

    public function destroy(OperatingCostPool $operatingCost): RedirectResponse
    {
        $operatingCost->delete();

        return back()->with('success', 'تم حذف بند التكلفة.');
    }

    public function updateExchangeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'openrouter_usd_to_egp_rate' => ['required', 'numeric', 'min:0.0001', 'max:10000'],
        ]);
        Setting::query()->firstOrCreate([])->update($data);

        return back()->with('success', 'تم تحديث سعر تحويل تكلفة OpenRouter للتقارير الجديدة.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'service_key' => ['required', Rule::in(array_keys(OperatingCostPool::SERVICES))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['required', Rule::in(['EGP', 'USD'])],
            'fx_rate_to_egp' => ['nullable', 'required_if:currency,USD', 'numeric', 'min:0.0001', 'max:10000'],
            'allocation_driver' => ['required', Rule::in(array_keys(OperatingCostPool::DRIVERS))],
            'is_final' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['is_final'] = $request->boolean('is_final');

        return $data;
    }
}
