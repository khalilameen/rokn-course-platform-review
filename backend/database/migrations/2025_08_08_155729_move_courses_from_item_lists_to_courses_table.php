<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class MoveCoursesFromItemListsToCoursesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Get the first grade as default
        $defaultGradeId = DB::table('grades')->value('id') ?? 1;

        // Get all courses from item_lists table
        $courses = DB::table('lists')
            ->where('type', 'course')
            ->get();

        foreach ($courses as $course) {
            // Insert into courses table with only available columns
            $courseId = DB::table('courses')->insertGetId([
                'name_ar' => $course->title,
                'name_en' => $course->title, // Use title for both Arabic and English
                'description_ar' => $course->description,
                'description_en' => $course->description, // Use description for both Arabic and English
                'image' => $course->image,
                'grade_id' => $defaultGradeId, // Use first available grade
                'tenant_id' => $course->tenant_id ?? 1, // Use existing tenant_id or default to 1
                'teacher_id' => $course->teacher_id ?? null,
                'store_id' => null,
                'created_at' => $course->created_at,
                'updated_at' => $course->updated_at,
            ]);

            // Update lessons to reference the new course ID
            DB::table('lessons')
                ->where('list_id', $course->id)
                ->update(['list_id' => $courseId]);

            // Update questions to reference the new course ID
            DB::table('questions')
                ->where('list_id', $course->id)
                ->update(['list_id' => $courseId]);

            // Update course sections to reference the new course ID
            DB::table('course_sections')
                ->where('sectionable_id', $course->id)
                ->where('sectionable_type', 'App\Models\ItemList')
                ->update([
                    'sectionable_id' => $courseId,
                    'sectionable_type' => 'App\Models\Course'
                ]);

            // Update social groups to reference the new course ID
            DB::table('social_groups')
                ->where('list_id', $course->id)
                ->update(['list_id' => $courseId]);

            // Update photos to reference the new course ID
            DB::table('photos')
                ->where('photoable_id', $course->id)
                ->where('photoable_type', 'App\Models\ItemList')
                ->update([
                    'photoable_id' => $courseId,
                    'photoable_type' => 'App\Models\Course'
                ]);

            // Store the mapping for parent/child relationships
            DB::table('course_mappings')->insert([
                'old_id' => $course->id,
                'new_id' => $courseId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        echo "Successfully moved " . $courses->count() . " courses from item_lists to courses table.\n";
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Get the mappings
        $mappings = DB::table('course_mappings')->get();

        foreach ($mappings as $mapping) {
            // Move data back to item_lists table
            $course = DB::table('courses')->find($mapping->new_id);
            if ($course) {
                DB::table('lists')->insert([
                    'id' => $mapping->old_id,
                    'title' => $course->name_ar, // Use name_ar as title
                    'description' => $course->description_ar, // Use description_ar as description
                    'category_id' => null, // courses table doesn't have category_id
                    'type' => 'course',
                    'priority' => 1, // Default priority
                    'image' => $course->image,
                    'is_opened' => 1, // Default to opened
                    'tenant_id' => $course->tenant_id,
                    'teacher_id' => $course->teacher_id,
                    'created_at' => $course->created_at,
                    'updated_at' => $course->updated_at,
                ]);

                // Update related tables back to item_lists
                DB::table('lessons')
                    ->where('list_id', $mapping->new_id)
                    ->update(['list_id' => $mapping->old_id]);

                DB::table('questions')
                    ->where('list_id', $mapping->new_id)
                    ->update(['list_id' => $mapping->old_id]);

                DB::table('course_sections')
                    ->where('sectionable_id', $mapping->new_id)
                    ->where('sectionable_type', 'App\Models\Course')
                    ->update([
                        'sectionable_id' => $mapping->old_id,
                        'sectionable_type' => 'App\Models\ItemList'
                    ]);

                DB::table('social_groups')
                    ->where('list_id', $mapping->new_id)
                    ->update(['list_id' => $mapping->old_id]);

                DB::table('photos')
                    ->where('photoable_id', $mapping->new_id)
                    ->where('photoable_type', 'App\Models\Course')
                    ->update([
                        'photoable_id' => $mapping->old_id,
                        'photoable_type' => 'App\Models\ItemList'
                    ]);
            }
        }

        // Clean up the courses table
        DB::table('courses')->truncate();
        DB::table('course_mappings')->truncate();

        echo "Successfully reverted courses back to item_lists table.\n";
    }
}
