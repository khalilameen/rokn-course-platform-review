<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\ItemList;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Support\UnicodeText;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;

final class QuizController extends Controller
{
    public function index(): View
    {
        $quizzes = ItemList::standaloneQuiz()->withCount('questions')->orderByDesc('updated_at')->get();

        return view('admin.quizzes.index', compact('quizzes'));
    }

    public function create(): View
    {
        $questions = Question::query()
            ->whereHas('itemList', fn ($quizzes) => $quizzes->standaloneQuiz())
            ->orderByDesc('updated_at')->get();

        return view('admin.quizzes.create', compact('questions'));
    }

    public function store(
        Request $request,
        AdminAuthoringCreateIntentService $createIntents
    ): JsonResponse|RedirectResponse
    {
        $validated = $this->validateQuiz($request, true);
        $request->validate(['authoring_request_id' => 'required|uuid']);
        if (!empty($validated['course_id'])) {
            throw ValidationException::withMessages([
                'course_id' => 'أضف اختبار الكورس من استوديو الكورس حتى يُحفظ مع وحدته وأسئلته كعملية واحدة',
            ]);
        }
        if (!empty($validated['exam_id'])) {
            throw ValidationException::withMessages([
                'exam_id' => 'افتح صفحة تعديل الاختبار بدل إرساله كاختبار جديد',
            ]);
        }
        $requestId = (string) $request->input('authoring_request_id');
        $existing = ItemList::quiz()->where('authoring_request_id', $requestId)->first();
        if ($existing) {
            DB::transaction(function () use ($request, $existing, $createIntents): void {
                ItemList::quiz()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
                $createIntents->checkpointResource($request, ItemList::class, $existing->id);
            }, 3);
            $this->ensureQuizImage($request, $existing, $requestId);
            $existing = $this->completeStoredIntent($request, $existing, $createIntents);
            return $this->storedResponse($request, $existing, true);
        }
        $alreadySaved = false;
        $inlineImages = $this->stageInlineQuestionImages($request, 'quiz-create|'.strtolower($requestId));

        try {
            $quiz = DB::transaction(function () use (
                $request,
                $validated,
                $requestId,
                $inlineImages,
                $createIntents
            ): ItemList {
                $quiz = new ItemList(['authoring_request_id' => $requestId]);
                $quiz->fill($this->quizPayload($validated))->save();

                $this->syncInlineQuestions($request, $quiz, $inlineImages);
                if (!$request->has('q_title')) {
                    $this->copySelectedQuestions($quiz, (array) ($validated['questions'] ?? []), false);
                }
                $createIntents->checkpointResource($request, ItemList::class, $quiz->id);

                return $quiz;
            }, 3);
        } catch (QueryException $exception) {
            $quiz = ItemList::quiz()->where('authoring_request_id', $requestId)->first();
            if (!$quiz) throw $exception;
            $alreadySaved = true;
        }

        $this->ensureQuizImage($request, $quiz, $requestId);
        $quiz = $this->completeStoredIntent($request, $quiz, $createIntents);

        return $this->storedResponse($request, $quiz, $alreadySaved);
    }

    public function copy(Request $request, ItemList $quiz): JsonResponse|RedirectResponse
    {
        abort_unless($quiz->type === 'quiz', 404);
        $this->assertStandalone($quiz);
        $validated = $request->validate([
            'editor_version' => 'required|string|size:64',
            'authoring_request_id' => 'required|uuid',
        ]);
        $existing = ItemList::quiz()
            ->where('authoring_request_id', $validated['authoring_request_id'])
            ->first();
        if ($existing) return $this->copiedResponse($request, $existing, true);

        try {
            $copy = DB::transaction(function () use ($quiz, $validated): ItemList {
                $lockedQuiz = ItemList::quiz()->whereKey($quiz->id)->lockForUpdate()->firstOrFail();
                $this->assertEditorVersion($lockedQuiz, (string) $validated['editor_version']);
                $copy = $lockedQuiz->replicate();
                $copy->authoring_request_id = $validated['authoring_request_id'];
                $copy->title_ar = 'نسخة من ' . $lockedQuiz->title_ar;
                $copy->title_en = $lockedQuiz->title_en ? 'Copy of ' . $lockedQuiz->title_en : null;
                $copy->save();

                foreach ($lockedQuiz->questions as $question) {
                    $questionCopy = $question->replicate();
                    $questionCopy->list_id = $copy->id;
                    $questionCopy->authoring_request_id = null;
                    $questionCopy->save();
                    if ($question->photo) {
                        $questionCopy->photos()->create([
                            'path' => $question->photo->path,
                            'type' => $question->photo->type,
                        ]);
                    }
                }
                if ($lockedQuiz->photo) {
                    $copy->photos()->create([
                        'path' => $lockedQuiz->photo->path,
                        'type' => $lockedQuiz->photo->type,
                    ]);
                }

                return $copy;
            }, 3);
        } catch (QueryException $exception) {
            $copy = ItemList::quiz()
                ->where('authoring_request_id', $validated['authoring_request_id'])
                ->first();
            if (!$copy) throw $exception;
        }

        return $this->copiedResponse($request, $copy, false);
    }

    public function edit(ItemList $quiz): View
    {
        abort_unless($quiz->type === 'quiz', 404);
        $this->assertStandalone($quiz);
        $questions = Question::query()
            ->whereHas('itemList', fn ($quizzes) => $quizzes->standaloneQuiz())
            ->orderByDesc('updated_at')->get();
        $quizQuestions = $quiz->questions->pluck('id')->all();

        return view('admin.quizzes.edit', compact('quiz', 'questions', 'quizQuestions'));
    }

    public function update(Request $request, ItemList $quiz): RedirectResponse
    {
        abort_unless($quiz->type === 'quiz', 404);
        $this->assertStandalone($quiz);
        $validated = $this->validateQuiz($request, true);
        $request->validate(['editor_version' => 'required|string|size:64']);
        if (!empty($validated['course_id'])) {
            throw ValidationException::withMessages([
                'course_id' => 'أضف اختبار الكورس من استوديو الكورس',
            ]);
        }

        $inlineImages = $this->stageInlineQuestionImages(
            $request,
            'quiz-update|'.$quiz->id.'|'.(string) $request->input('editor_version')
        );
        DB::transaction(function () use ($request, $quiz, $validated, $inlineImages): void {
            $lockedQuiz = ItemList::quiz()->whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $this->assertEditorVersion($lockedQuiz, (string) $request->input('editor_version'));
            $lockedQuiz->update($this->quizPayload($validated));
            $this->syncInlineQuestions($request, $lockedQuiz, $inlineImages);
            if (!$request->has('q_title')) {
                $this->copySelectedQuestions($lockedQuiz, (array) ($validated['questions'] ?? []), true);
            }
        }, 3);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $quiz->replaceImage(
                $file,
                'quizzes',
                'featured',
                'quiz-update|'.$quiz->id.'|'.(string) $request->input('editor_version').'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        return !empty($validated['course_id'])
            ? redirect()->route('admin.courses.sections.index', $validated['course_id'])->with('success', 'تم تعديل الاختبار')
            : redirect()->route('admin.quizzes.index')->with('success', 'تم تعديل الاختبار');
    }

    public function destroy(Request $request, ItemList $quiz): RedirectResponse
    {
        abort_unless($quiz->type === 'quiz', 404);
        $this->assertStandalone($quiz);
        $request->validate(['editor_version' => 'required|string|size:64']);
        if (\App\Models\ExamAttempt::withTrashed()->where('quiz_id', $quiz->id)->exists()) {
            return redirect()->route('admin.quizzes.index')->with(
                'error',
                'لا يمكن حذف اختبار له محاولات طلاب محفوظة'
            );
        }
        DB::transaction(function () use ($request, $quiz): void {
            $lockedQuiz = ItemList::quiz()->whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $this->assertEditorVersion($lockedQuiz, (string) $request->input('editor_version'));
            CourseSection::query()
                ->where('sectionable_type', ItemList::class)
                ->where('sectionable_id', $lockedQuiz->id)
                ->delete();
            $lockedQuiz->questions()->get()->each->delete();
            $lockedQuiz->delete();
        }, 3);

        return redirect()->route('admin.quizzes.index')->with('success', 'تم حذف الاختبار');
    }

    /** @return array<string, mixed> */
    private function validateQuiz(Request $request, bool $supportsInlineQuestions): array
    {
        $normalized = [];
        foreach (['title_ar', 'title_en', 'description_ar', 'description_en'] as $field) {
            if ($request->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean(
                    $request->input($field),
                    !str_starts_with($field, 'title')
                );
            }
        }
        foreach (['q_title', 'q_question', 'q_choice1', 'q_choice2', 'q_choice3', 'q_choice4', 'q_choice5', 'q_choice6'] as $field) {
            $values = $request->input($field);
            if (!is_array($values)) continue;
            $normalized[$field] = array_map(
                static fn ($value) => is_string($value)
                    ? UnicodeText::clean($value, $field !== 'q_title')
                    : $value,
                $values
            );
        }
        if ($normalized !== []) $request->merge($normalized);

        $rules = [
            'exam_id' => 'nullable|integer|exists:lists,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'description_ar' => 'nullable|string|max:3000',
            'description_en' => 'nullable|string|max:3000',
            'priority' => 'nullable|integer|min:0|max:100000',
            'time_minutes' => 'nullable|integer|min:1|max:300',
            'image' => 'nullable|image|max:4096',
            'questions' => 'nullable|array|max:200',
            'questions.*' => 'integer|exists:questions,id',
        ];
        if ($supportsInlineQuestions) {
            foreach (['q_question_id', 'q_title', 'q_question', 'q_choice1', 'q_choice2', 'q_choice3', 'q_choice4', 'q_choice5', 'q_choice6', 'q_right_answer', 'q_question_image'] as $field) {
                $rules[$field] = 'nullable|array|max:200';
            }
            $rules += [
                'q_question_id.*' => 'nullable|integer|exists:questions,id',
                'q_title.*' => 'nullable|string|max:255',
                'q_question.*' => 'nullable|string|max:3000',
                'q_choice1.*' => 'nullable|string|max:1000',
                'q_choice2.*' => 'nullable|string|max:1000',
                'q_choice3.*' => 'nullable|string|max:1000',
                'q_choice4.*' => 'nullable|string|max:1000',
                'q_choice5.*' => 'nullable|string|max:1000',
                'q_choice6.*' => 'nullable|string|max:1000',
                'q_right_answer.*' => 'nullable|integer|min:1|max:6',
                'q_question_image.*' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:4096',
            ];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function quizPayload(array $validated): array
    {
        return [
            'title_ar' => trim((string) $validated['title_ar']),
            'title_en' => $validated['title_en'] ?? null,
            'description_ar' => $validated['description_ar'] ?? null,
            'description_en' => $validated['description_en'] ?? null,
            'priority' => (int) ($validated['priority'] ?? 0),
            'time_minutes' => $validated['time_minutes'] ?? null,
            'type' => 'quiz',
        ];
    }

    /** @param array<int|string, string> $stagedImages */
    private function syncInlineQuestions(Request $request, ItemList $quiz, array $stagedImages = []): void
    {
        if (!$request->has('q_title')) {
            return;
        }

        $keptIds = [];
        foreach ((array) $request->input('q_title', []) as $key => $rawTitle) {
            $title = trim((string) $rawTitle);
            $questionText = trim((string) $request->input("q_question.{$key}", ''));
            $choices = [];
            for ($number = 1; $number <= 6; $number++) {
                $choices[$number] = trim((string) $request->input("q_choice{$number}.{$key}", ''));
            }
            $rightAnswer = (int) $request->input("q_right_answer.{$key}", 0);
            if ($title === '' && $questionText === '' && $choices[1] === '' && $choices[2] === '') {
                continue;
            }
            if ($title === '' || $questionText === '' || $choices[1] === '' || $choices[2] === '' || $rightAnswer < 1 || $rightAnswer > 6 || $choices[$rightAnswer] === '') {
                throw ValidationException::withMessages([
                    "q_title.{$key}" => 'أكمل السؤال واختيارين على الأقل وحدد إجابة صحيحة مكتوبة',
                ]);
            }

            $questionId = (int) $request->input("q_question_id.{$key}", 0);
            $question = $questionId > 0
                ? $quiz->questions()->whereKey($questionId)->firstOrFail()
                : new Question(['list_id' => $quiz->id]);
            $question->fill([
                'title' => $title,
                'question' => $questionText,
                'priority' => (int) $key,
                'choice1' => $choices[1],
                'choice2' => $choices[2],
                'choice3' => $choices[3] ?: null,
                'choice4' => $choices[4] ?: null,
                'choice5' => $choices[5] ?: null,
                'choice6' => $choices[6] ?: null,
                'right_answer' => $rightAnswer,
            ])->save();
            $keptIds[] = (int) $question->id;

            $imagePath = $stagedImages[$key] ?? null;
            if (is_string($imagePath) && $imagePath !== '') {
                $oldPhotos = $question->allPhotos()->where('type', 'featured')->get();
                $newPhoto = $question->allPhotos()->firstOrCreate([
                    'path' => $imagePath,
                    'type' => 'featured',
                ]);
                $oldPhotos->where('id', '!=', $newPhoto->id)->each->delete();
            }
        }

        $quiz->questions()->whereNotIn('id', $keptIds ?: [0])->get()->each->delete();
    }

    /** @return array<int|string, string> */
    private function stageInlineQuestionImages(Request $request, string $operation): array
    {
        $paths = [];
        foreach ((array) $request->file('q_question_image', []) as $key => $image) {
            if (!$image instanceof UploadedFile) continue;
            $sha = hash_file('sha256', $image->getRealPath());
            if (!is_string($sha) || $sha === '') {
                throw new \RuntimeException('Question image could not be fingerprinted.');
            }
            $paths[$key] = app(StoredFileDeletionService::class)->storeTrackedUpload(
                $image,
                'questions',
                'public',
                60,
                $operation.'|'.$key.'|'.$sha
            );
        }
        return $paths;
    }

    private function ensureQuizImage(Request $request, ItemList $quiz, string $operation): void
    {
        if (!$request->hasFile('image')) return;
        $file = $request->file('image');
        $quiz->replaceImage(
            $file,
            'quizzes',
            'featured',
            'admin-quiz|'.strtolower($operation).'|'.hash_file('sha256', $file->getRealPath())
        );
    }

    /** @param array<int, mixed> $questionIds */
    private function copySelectedQuestions(ItemList $quiz, array $questionIds, bool $replaceExisting): void
    {
        $ids = array_values(array_unique(array_map('intval', $questionIds)));
        $sources = Question::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($sources->count() !== count($ids)) {
            throw ValidationException::withMessages(['questions' => 'أحد الأسئلة المختارة لم يعد متاحًا']);
        }

        $keptIds = [];
        foreach ($ids as $sourceId) {
            $source = $sources->get($sourceId);
            if ((int) $source->list_id === (int) $quiz->id) {
                $keptIds[] = (int) $source->id;
                continue;
            }
            $copy = $source->replicate();
            $copy->list_id = $quiz->id;
            $copy->authoring_request_id = null;
            $copy->save();
            if ($source->photo) {
                $copy->photos()->create([
                    'path' => $source->photo->path,
                    'type' => $source->photo->type,
                ]);
            }
            $keptIds[] = (int) $copy->id;
        }

        if ($replaceExisting) {
            $quiz->questions()->whereNotIn('id', $keptIds ?: [0])->get()->each->delete();
        }
    }

    private function ensureCourseSection(ItemList $quiz, int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $section = CourseSection::query()
            ->where('course_id', $courseId)
            ->where('sectionable_type', ItemList::class)
            ->where('sectionable_id', $quiz->id)
            ->first();
        if (!$section) {
            $section = new CourseSection([
                'course_id' => $courseId,
                'sectionable_type' => ItemList::class,
                'sectionable_id' => $quiz->id,
                'order' => ((int) $course->sections()->max('order')) + 1,
            ]);
        }
        $section->title_ar = (string) $quiz->title_ar;
        $section->title_en = (string) ($quiz->title_en ?: $quiz->title_ar);
        $section->save();
    }

    private function assertStandalone(ItemList $quiz): void
    {
        if ($quiz->course_id || CourseSection::query()
            ->where('sectionable_type', ItemList::class)
            ->where('sectionable_id', $quiz->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'quiz' => 'هذا الاختبار جزء من كورس\nعدّله من استوديو الكورس حتى لا تتجاوز المسودة وفحص النشر',
            ]);
        }
    }

    private function assertEditorVersion(ItemList $quiz, string $submitted): void
    {
        $current = hash('sha256', $quiz->id . '|' . optional($quiz->updated_at)->format('Y-m-d H:i:s.u'));
        if (!hash_equals($current, $submitted)) {
            throw ValidationException::withMessages([
                'editor_version' => 'تغيّر الاختبار منذ فتح الصفحة\nأعد تحميله ثم راجع التعديل',
            ]);
        }
    }

    private function storedResponse(Request $request, ItemList $quiz, bool $alreadySaved): JsonResponse|RedirectResponse
    {
        if ($request->ajax()) {
            return response()->json(['quiz' => $quiz]);
        }

        return redirect()->route('admin.quizzes.index')
            ->with('success', $alreadySaved ? 'تم حفظ الاختبار بالفعل' : 'تم حفظ الاختبار');
    }

    private function completeStoredIntent(
        Request $request,
        ItemList $quiz,
        AdminAuthoringCreateIntentService $createIntents
    ): ItemList {
        return DB::transaction(function () use ($request, $quiz, $createIntents): ItemList {
            $locked = ItemList::quiz()->whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $locked->load('questions');
            if ($request->ajax()) {
                $createIntents->completeJson(
                    $request,
                    ['quiz' => $locked],
                    200,
                    ItemList::class,
                    $locked->id
                );
            } else {
                $createIntents->completeRedirect(
                    $request,
                    route('admin.quizzes.index'),
                    302,
                    ItemList::class,
                    $locked->id
                );
            }
            return $locked;
        }, 3);
    }

    private function copiedResponse(Request $request, ItemList $copy, bool $alreadyCopied): JsonResponse|RedirectResponse
    {
        return $request->ajax()
            ? response()->json($copy->load('questions'))
            : redirect()->route('admin.quizzes.index')
                ->with('success', $alreadyCopied ? 'تم إنشاء النسخة بالفعل' : 'تم نسخ الاختبار');
    }
}
