<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class CourseRevisionChangedException extends RuntimeException
{
    public function __construct(
        public readonly int $courseId,
        public readonly int $publishedRevision
    ) {
        parent::__construct('The learner is using a superseded course revision.');
    }

    /** @return array{course_id:int,published_revision:int,reload_endpoint:string} */
    public function contract(): array
    {
        return [
            'course_id' => $this->courseId,
            'published_revision' => $this->publishedRevision,
            'reload_endpoint' => "/api/v1/courses/{$this->courseId}/details",
        ];
    }
}
