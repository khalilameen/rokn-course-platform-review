<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\ItemList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use App\Support\UnicodeText;

class QuestionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $questions = Question::with('itemList')
            ->whereHas('itemList', fn ($quizzes) => $quizzes->standaloneQuiz());
        
        if ($request->integer('quiz_id') > 0) {
            $questions = $questions->where('list_id', $request->integer('quiz_id'));
        }
        
        $questions = $questions
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();
        $quizzes = ItemList::standaloneQuiz()
            ->orderBy('title_ar')
            ->orderBy('id')
            ->get(['id', 'title_ar', 'title_en', 'title']);

        return view('admin.questions.index', compact('questions', 'quizzes'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $quizzes = ItemList::standaloneQuiz()->orderBy('title_ar')->get();
        return view('admin.questions.create', compact('quizzes'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $payload = $this->validatedPayload($request);
        $request->validate(['authoring_request_id' => 'required|uuid']);
        $this->assertStandaloneQuiz((int) $payload['list_id']);
        $requestId = (string) $request->input('authoring_request_id');
        $question = Question::query()->where('authoring_request_id', $requestId)->first();
        $alreadySaved = $question !== null;
        if (!$question) {
            try {
                $question = DB::transaction(function () use ($payload, $requestId): Question {
                    $quiz = ItemList::quiz()->whereKey($payload['list_id'])->lockForUpdate()->firstOrFail();
                    $this->assertStandaloneQuiz((int) $quiz->id);
                    return Question::create($payload + ['authoring_request_id' => $requestId]);
                }, 3);
            } catch (QueryException $exception) {
                $question = Question::query()->where('authoring_request_id', $requestId)->first();
                if (!$question) throw $exception;
                $alreadySaved = true;
            }
        }
        
        if (!$alreadySaved && $request->hasFile('image')) {
            $file = $request->file('image');
            $question->storeImage($file, 'questions', 'featured');
        }

        return redirect()->route('admin.questions.index')->with('success', 'تمت الإضافة بنجاح');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Question  $question
     * @return \Illuminate\Http\Response
     */
    public function show(Question $question)
    {
        return redirect()->route('admin.questions.edit', $question);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Question  $question
     * @return \Illuminate\Http\Response
     */
    public function edit(Question $question)
    {
        $this->assertStandaloneQuiz((int) $question->list_id);
        $quizzes = ItemList::standaloneQuiz()->orderBy('title_ar')->get();
        return view('admin.questions.edit', compact('question', 'quizzes'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Question  $question
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Question $question)
    {
        $this->assertStandaloneQuiz((int) $question->list_id);
        $payload = $this->validatedPayload($request);
        $request->validate(['editor_version' => 'required|string|size:64']);
        $this->assertStandaloneQuiz((int) $payload['list_id']);
        DB::transaction(function () use ($request, $question, $payload): void {
            $locked = Question::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            $this->assertEditorVersion($locked, (string) $request->input('editor_version'));
            $this->assertStandaloneQuiz((int) $locked->list_id);
            $this->assertStandaloneQuiz((int) $payload['list_id']);
            $locked->update($payload);
        }, 3);
        
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $question->replaceImage($file, 'questions', 'featured');
        }

        return redirect()->route('admin.questions.index')->with('success', 'تم التحديث بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Question  $question
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Question $question)
    {
        $this->assertStandaloneQuiz((int) $question->list_id);
        $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $question): void {
            $locked = Question::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            $this->assertEditorVersion($locked, (string) $request->input('editor_version'));
            $this->assertStandaloneQuiz((int) $locked->list_id);
            $locked->delete();
        }, 3);
        return redirect()->route('admin.questions.index')->with('success', 'تم الحذف بنجاح');
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request): array
    {
        $normalized = [];
        foreach (['title', 'description', 'question', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'choice6'] as $field) {
            if ($request->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($request->input($field), $field !== 'title');
            }
        }
        if ($normalized !== []) $request->merge($normalized);

        return $request->validate([
            'title' => 'required|string|max:255',
            'list_id' => 'required|integer|exists:lists,id',
            'priority' => 'nullable|integer|min:0|max:100000',
            'description' => 'nullable|string|max:2000',
            'question' => 'required|string|max:3000',
            'choice1' => 'required|string|max:1000',
            'choice2' => 'required|string|max:1000',
            'choice3' => 'nullable|string|max:1000',
            'choice4' => 'nullable|string|max:1000',
            'choice5' => 'nullable|string|max:1000',
            'choice6' => 'nullable|string|max:1000',
            'right_answer' => 'required|integer|min:1|max:6',
            'image' => 'nullable|image|max:4096',
        ]);
    }

    private function assertStandaloneQuiz(int $quizId): void
    {
        $quiz = ItemList::quiz()->findOrFail($quizId);
        if ($quiz->course_id || $quiz->courseSection()->exists()) {
            throw ValidationException::withMessages([
                'list_id' => 'هذا السؤال داخل كورس\nعدّله من استوديو الكورس حتى يُحفظ مع الاختبار كاملًا',
            ]);
        }
    }

    private function assertEditorVersion(Question $question, string $submitted): void
    {
        $current = hash('sha256', $question->id . '|' . optional($question->updated_at)->format('Y-m-d H:i:s.u'));
        if (!hash_equals($current, $submitted)) {
            throw ValidationException::withMessages([
                'editor_version' => 'تغيّر السؤال منذ فتح الصفحة\nأعد تحميله ثم راجع التعديل',
            ]);
        }
    }
}
