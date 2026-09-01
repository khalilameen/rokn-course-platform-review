<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseModule;
use Illuminate\Support\Collection;

final class CourseSectionSequenceService
{
    private const LEARNING_TYPES = ['lesson', 'project', 'quiz'];

    /**
     * Only content represented by the learner can take part in progression.
     * Legacy links, nested courses and inline files are authoring leftovers;
     * the publishing gate rejects them and they must not strand older buyers.
     */
    public function learning(Collection $sections): Collection
    {
        return $this->ordered(
            $sections->filter(
                fn ($section): bool => in_array($section->getSectionType(), self::LEARNING_TYPES, true)
            )
        );
    }

    /**
     * Section order is local to a module. Build the actual course sequence from
     * module order first, then section order. Legacy ungrouped sections remain
     * ordered and are placed after authored modules.
     */
    public function ordered(Collection $sections): Collection
    {
        $moduleIds = $sections->pluck('module_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        if ($moduleIds->isEmpty()) {
            return $sections->sortBy([
                ['order', 'asc'],
                ['id', 'asc'],
            ])->values();
        }

        $moduleOrders = CourseModule::query()
            ->whereIn('id', $moduleIds)
            ->pluck('order', 'id');
        $ungroupedOrder = ((int) $moduleOrders->max()) + 1;

        return $sections->sortBy(function ($section) use ($moduleOrders, $ungroupedOrder): string {
            $moduleOrder = $section->module_id
                ? (int) ($moduleOrders->get((int) $section->module_id) ?? $ungroupedOrder)
                : $ungroupedOrder;

            return sprintf(
                '%010d:%010d:%010d',
                $moduleOrder,
                (int) $section->order,
                (int) $section->id
            );
        })->values();
    }
}
