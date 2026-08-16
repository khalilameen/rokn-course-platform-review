<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The dashboard role matrix is deliberately an allow-list.
 *
 * Administrators retain full dashboard access. Moderators only receive the
 * named educational workflows below; a newly-added or unnamed route is denied
 * until it is reviewed and explicitly added here.
 */
final class AdminPermissionMatrix
{
    /** @var array<string, list<string>> */
    private const MODERATOR_RULES = [
        'admin.dashboard' => ['GET'],
        'admin.mfa.setup' => ['GET'],
        'admin.mfa.setup.confirm' => ['POST'],
        'admin.mfa.challenge' => ['GET'],
        'admin.mfa.challenge.verify' => ['POST'],
        'admin.mfa.backup-codes' => ['GET'],

        // Course shells are read-only for moderators. Pricing, publication and
        // destructive shell changes remain administrator-only at route level.
        'admin.courses.index' => ['GET'],
        'admin.courses.show' => ['GET'],

        // Educational content authoring and review workflows.
        'admin.courses.sections.index' => ['GET'],
        'admin.courses.sections.create' => ['GET'],
        'admin.courses.sections.store' => ['POST'],
        'admin.courses.sections.show' => ['GET'],
        'admin.courses.sections.edit' => ['GET'],
        'admin.courses.sections.update' => ['PUT', 'PATCH'],
        'admin.courses.sections.destroy' => ['DELETE'],
        'admin.courses.sections.reorder' => ['POST'],
        'admin.courses.sections.autoSaveQuiz' => ['POST'],
        'admin.courses.sections.deleteQuizQuestion' => ['POST'],
        'admin.courses.modules.create' => ['GET'],
        'admin.courses.modules.store' => ['POST'],
        'admin.courses.modules.edit' => ['GET'],
        'admin.courses.modules.update' => ['PUT', 'PATCH'],
        'admin.courses.modules.destroy' => ['DELETE'],
        'admin.courses.modules.reorder' => ['POST'],
        'admin.courses.pdfs.index' => ['GET'],
        'admin.courses.pdfs.create' => ['GET'],
        'admin.courses.pdfs.store' => ['POST'],
        'admin.courses.pdfs.show' => ['GET'],
        'admin.courses.pdfs.edit' => ['GET'],
        'admin.courses.pdfs.update' => ['PUT', 'PATCH'],
        'admin.courses.pdfs.destroy' => ['DELETE'],
        'admin.courses.pdfs.reorder' => ['POST'],
        'admin.courses.pdfs.toggle-status' => ['POST'],
        'admin.courses.pdfs.preview' => ['GET'],
        'admin.attachments.store' => ['POST'],
        'admin.attachments.destroy' => ['DELETE'],
        'admin.quizzes.index' => ['GET'],
        'admin.quizzes.create' => ['GET'],
        'admin.quizzes.store' => ['POST'],
        'admin.quizzes.show' => ['GET'],
        'admin.quizzes.edit' => ['GET'],
        'admin.quizzes.update' => ['PUT', 'PATCH'],
        'admin.quizzes.destroy' => ['DELETE'],
        'admin.quizzes.copy' => ['POST'],
        'admin.random-quizzes.index' => ['GET'],
        'admin.random-quizzes.create' => ['GET'],
        'admin.random-quizzes.store' => ['POST'],
        'admin.random-quizzes.show' => ['GET'],
        'admin.random-quizzes.edit' => ['GET'],
        'admin.random-quizzes.update' => ['PUT', 'PATCH'],
        'admin.random-quizzes.destroy' => ['DELETE'],
        'admin.questions.index' => ['GET'],
        'admin.questions.create' => ['GET'],
        'admin.questions.store' => ['POST'],
        'admin.questions.show' => ['GET'],
        'admin.questions.edit' => ['GET'],
        'admin.questions.update' => ['PUT', 'PATCH'],
        'admin.questions.destroy' => ['DELETE'],
        'admin.lessons.index' => ['GET'],
        'admin.lessons.create' => ['GET'],
        'admin.lessons.show' => ['GET'],
        'admin.lessons.edit' => ['GET'],

        // Read-only curriculum reference data.
        'admin.categories.index' => ['GET'],
        'admin.categories.show' => ['GET'],
        'admin.grades.index' => ['GET'],
        'admin.grades.show' => ['GET'],
        'admin.grades.courses' => ['GET'],
        'admin.classifications.index' => ['GET'],
        'admin.classifications.show' => ['GET'],
        'admin.paths.index' => ['GET'],
        'admin.paths.show' => ['GET'],
        'admin.levels.index' => ['GET'],
        'admin.levels.show' => ['GET'],

        // Moderation and learning-quality review.
        'admin.student-progress.index' => ['GET'],
        'admin.student-progress.show' => ['GET'],
        'admin.student-progress.statistics' => ['GET'],
        'admin.student-progress.compare' => ['POST'],
        'admin.project-submissions.index' => ['GET'],
        'admin.project-submissions.show' => ['GET'],
        'admin.project-submissions.download' => ['GET'],
        'admin.project-submissions.pass' => ['POST'],
        'admin.project-submissions.reject' => ['POST'],
        'admin.exam-results.index' => ['GET'],
        'admin.exam-results.stats' => ['GET'],
        'admin.exam-results.show' => ['GET'],
        'admin.students.exam-results' => ['GET'],
    ];

    public function allows(?string $role, ?string $routeName, string $method): bool
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'admin') {
            return true;
        }

        if ($role !== 'moderator' || blank($routeName)) {
            return false;
        }

        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $methods = self::MODERATOR_RULES[$routeName] ?? null;

        return is_array($methods) && in_array($method, $methods, true);
    }

    /** @return array<string, list<string>> */
    public function moderatorRules(): array
    {
        return self::MODERATOR_RULES;
    }
}
