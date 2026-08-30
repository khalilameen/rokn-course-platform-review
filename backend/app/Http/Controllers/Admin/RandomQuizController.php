<?php

namespace App\Http\Controllers\Admin;

use App\Models\ItemList;
use App\Models\Question;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuizzRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;


class RandomQuizController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $randomQuizzes  = RandomQuiz::all();

        return view('admin.random-quizzes.index', compact('randomQuizzes'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $courses = \App\Models\Course::all();
        return view('admin.random-quizzes.create',compact('courses'));
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
        
        return view('admin.random-quizzes.exist',compact('quizzes','quizzesXML'));
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
        $randomQuiz = RandomQuiz::create();
            return redirect()->route('admin.random-quizzes.index')->with('success', 'تم الحفظ بنجاح');
            
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
            return response()
            ->json($quiz);
        }
        return redirect()->route('admin.random-quizzes.index')->with('success', 'تم الحفظ بنجاح');
        
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function edit(RandomQuiz $randomQuiz)
    {
        $courses = \App\Models\Course::all();
        return view('admin.random-quizzes.edit', compact('randomQuiz','courses'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, RandomQuiz $randomQuiz)
    {
        $randomQuiz->update($request->input());


        return redirect()->route('admin.random-quizzes.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Quizz  $quizz
     * @return \Illuminate\Http\Response
     */
    public function destroy(RandomQuiz $randomQuiz)
    {
         $randomQuiz->delete();

        return redirect()->route('admin.quizzes.index')->with('success', 'تم الحذف بنجاح ');
    }
}
