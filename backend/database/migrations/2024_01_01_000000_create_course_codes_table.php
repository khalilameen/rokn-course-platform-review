<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // SQLite accepts a foreign key that points at a table created later in
        // the same migration run. MySQL does not, so defer that one constraint
        // until the release-tail compatibility migration on clean installs.
        $canReferenceCourses = Schema::hasTable('courses')
            || Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('course_codes', function (Blueprint $table) use ($canReferenceCourses) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 50)->unique();
            $table->string('name')->nullable(); // Optional name for the code
            $table->enum('type', ['course', 'lesson', 'multiple_lessons'])->default('course');
            $table->unsignedBigInteger('course_id')->nullable()->index();
            $table->json('lesson_ids')->nullable(); // For multiple lessons
            $table->unsignedBigInteger('lesson_id')->nullable()->index();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->integer('max_uses')->default(1); // Number of times code can be used
            $table->integer('used_count')->default(0); // Number of times code has been used
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            
            if ($canReferenceCourses) {
                $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            }
            // $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('course_codes');
    }
};
