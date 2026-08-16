<?php

namespace App\Http\Controllers\Admin;

use App\Models\ItemList;
use App\Models\Question;
use App\Models\CourseSection;
use App\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuizzRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;


class QuizController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $quizzes  = ItemList::quiz()->get();

        return view('admin.quizzes.index', compact('quizzes'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $questions = \App\Models\Question::all();
        return view('admin.quizzes.create',compact('questions'));
    }

    public function exist(){
        $quizzes = Quizz::with('category')->whereNotNull('user_id')->where('active',1)->get(['id','name_ar','description_ar','lat','lng']);
        $quizzesXML = '';
       /* $quizzesXML.= "<?xml version='1.0' ?>";*/
        $quizzesXML.= '<markers>';
        $ind=0;
        foreach ($quizzes as $quizz) {

            $cat_name = ($quiz->category)? $quiz->category->name_ar:'';
              // Add to XML document node
              $quizzesXML.= '<marker ';
              $quizzesXML.= 'id="' .  $quiz->id . '" ';
              $quizzesXML.= 'name="' . $this->parseToXML($quiz->name_ar) . '" ';
              $quizzesXML.= 'address="' . $this->parseToXML($quiz->description_ar) . '" ';
              $quizzesXML.= 'lat="' . $quiz->lat . '" ';
              $quizzesXML.= 'lng="' . $quiz->lng. '" ';
              $quizzesXML.= 'type="' . $cat_name . '" ';
              $quizzesXML.= '/>';
              $ind = $ind + 1;
            }

            // End XML file
            $quizzesXML.= '</markers>';

        return view('admin.quizzes.exist',compact('quizzes','quizzesXML'));
    }
function parseToXML($htmlStr)
{
$xmlStr=str_replace('<','&lt;',$htmlStr);
$xmlStr=str_replace('>','&gt;',$xmlStr);
$xmlStr=str_replace('"','&quot;',$xmlStr);
$xmlStr=str_replace("'",'&#39;',$xmlStr);
$xmlStr=str_replace("&",'&amp;',$xmlStr);
return $xmlStr;
}
    /**
     * Quizz a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(empty($request->exam_id)){
            $quiz = ItemList::quiz()->create($request->input());
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $quiz->storeImage($file, 'quizzes', 'featured');
            }

            if(isset($request->q_title) && count($request->q_title)){
                //q_priority q_description q_question  q_choice1  q_choice2 q_choice3  q_choice4  q_choice5  q_choice6 q_right_answer
                foreach($request->q_title as $key => $value){
                    $question = new Question();
                    $question->title = $request->q_title[$key];
                    $question->priority = 0;
                   // $question->description = $request->q_description[$key];
                    //$question->question = $request->q_question[$key];
    //dd($request->q_choice1);
                    $question->choice1 = $request->q_choice1[$key];
                    $question->choice2 = $request->q_choice2[$key];
                    $question->choice3 = $request->q_choice3[$key];
                    $question->choice4 = $request->q_choice4[$key];
                    $question->choice5 = $request->q_choice5[$key];
                    $question->choice6 = $request->q_choice6[$key];
                    $question->right_answer = isset($request->q_right_answer[$key]) ? $request->q_right_answer[$key] : 1 ;
                    $question->list_id = $quiz->id;
                    $question->save();
                    if (isset($request->q_question_image[$key]) && $request->q_question_image[$key] instanceof UploadedFile) {
                        $file = $request->q_question_image[$key];
                        $question->replaceImage($file, 'questions', 'featured');
                    }
                }
            }
            $quiz->refresh();

            if($request->has('course_id')) {
                $courseId = $request->course_id;

                // Get the course to ensure it exists
                $course = Course::findOrFail($courseId);

                // Get the highest order for this course
                $maxOrder = $course->sections()->max('order') ?? 0;
                $order = $maxOrder + 1;

                // Create CourseSection record to associate the quiz with the course
                CourseSection::create([
                    'title' => $quiz->title,
                    'course_id' => $courseId,
                    'order' => $order,
                    'sectionable_type' => ItemList::class,
                    'sectionable_id' => $quiz->id
                ]);
            }
            }else{
             $quiz = ItemList::quiz()->find($request->exam_id);
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $quiz->storeImage($file, 'quizzes', 'featured');
            }

            foreach($quiz->questions as $question){
                $question->delete();
            }
            if(isset($request->q_title) && count($request->q_title)){
                //q_priority q_description q_question  q_choice1  q_choice2 q_choice3  q_choice4  q_choice5  q_choice6 q_right_answer
                foreach($request->q_title as $key => $value){
                    $question = new Question();
                    $question->title = $request->q_title[$key];
                    $question->priority = 0;
                   // $question->description = $request->q_description[$key];
                    $question->question = $request->q_question[$key];
                    $question->choice1 = $request->q_choice1[$key];
                    $question->choice2 = $request->q_choice2[$key];
                    $question->choice3 = $request->q_choice3[$key];
                    $question->choice4 = $request->q_choice4[$key];
                    $question->choice5 = $request->q_choice5[$key];
                    $question->choice6 = $request->q_choice6[$key];
                    $question->right_answer = isset($request->q_right_answer[$key]) ? $request->q_right_answer[$key] : 1 ;
                    $question->list_id = $quiz->id;
                    $question->save();
                    if (isset($request->q_question_image[$key]) && $request->q_question_image[$key] instanceof UploadedFile) {
                        $file = $request->q_question_image[$key];
                        $question->replaceImage($file, 'questions', 'featured');
                    }
                }
            }
        }
        if($request->ajax()){
            $response = ['quiz' => $quiz];

            // If course_id is provided, include it in the response
            if($request->has('course_id')) {
                $response['course_id'] = $request->course_id;
            }

            return response()->json($response);
        }

        // If course_id is provided, create CourseSection and redirect back to course sections page
        if($request->has('course_id')) {
            $courseId = $request->course_id;
            return redirect()->route('admin.courses.sections.index', $courseId)->with('success', 'تم إنشاء الاختبار بنجاح');
        }

        return redirect()->route('admin.quizzes.index')->with('success', 'تم الحفظ بنجاح');

    }

    public function copy(Request $request, ItemList $quiz)
    {
        $newQuiz = $quiz->replicate();
        $newQuiz->title = 'نسخة من ' . $newQuiz->title;
        $newQuiz->save();
        
        // Clone the quiz image (photo relationship)
        if ($quiz->photo) {
            $originalPhoto = $quiz->photo;
            $newQuiz->photos()->create([
                'path' => $originalPhoto->path,
                'type' => $originalPhoto->type
            ]);
        }
        
        foreach($quiz->questions as $question){
            $newQuestion = $question->replicate();
            $newQuestion->list_id = $newQuiz->id;
            $newQuestion->save();
            
            // Clone the question image if exists
            if ($question->photo) {
                $originalQuestionPhoto = $question->photo;
                $newQuestion->photos()->create([
                    'path' => $originalQuestionPhoto->path,
                    'type' => $originalQuestionPhoto->type
                ]);
            }
        }

        if($request->ajax()){
            return response()
            ->json($newQuiz);
        }
        return redirect()->route('admin.quizzes.index')->with('success', 'تم النسخ بنجاح');


    }
    /**
     * Display the specified resource.
     *
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function show(ItemList $quiz)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function edit(ItemList $quiz)
    {
        $questions = \App\Models\Question::all();
        $quizQuestions = $quiz->questions->pluck('id')->toArray();
         return view('admin.quizzes.edit', compact('quiz','questions','quizQuestions'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, ItemList $quiz)
    {
        $quiz->update($request->input());
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $quiz->replaceImage($file, 'quizzes', 'featured');
        }
        foreach($request->questions as $question_id){
            $question = Question::find($question_id);
            $question->list_id = $quiz->id;
            $question->save();
        }

        // If course_id is provided, redirect back to course sections page
        if($request->has('course_id')) {
            $courseId = $request->course_id;
            return redirect()->route('admin.courses.sections.index', $courseId)->with('success', 'تم تعديل الاختبار بنجاح');
        }

        return redirect()->route('admin.quizzes.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function destroy(ItemList $quiz)
    {
         $quiz->delete();

        return redirect()->route('admin.quizzes.index')->with('success', 'تم الحذف بنجاح ');
    }
}
