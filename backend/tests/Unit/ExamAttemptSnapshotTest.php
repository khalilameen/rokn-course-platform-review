<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExamAttempt;
use PHPUnit\Framework\TestCase;

final class ExamAttemptSnapshotTest extends TestCase
{
    public function test_historical_presentation_does_not_require_the_live_quiz_relation(): void
    {
        $attempt = new ExamAttempt([
            'quiz_title' => 'اختبار وقت المحاولة',
            'quiz_description' => 'الوصف المحفوظ',
            'quiz_image' => 'https://cdn.example.test/exam.jpg',
        ]);
        $attempt->setRelation('quiz', null);

        self::assertSame([
            'title' => 'اختبار وقت المحاولة',
            'description' => 'الوصف المحفوظ',
            'image' => 'https://cdn.example.test/exam.jpg',
        ], $attempt->quizSnapshot());
    }
}
