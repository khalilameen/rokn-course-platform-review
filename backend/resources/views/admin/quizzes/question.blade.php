
<div class="question_div">
    <div class="question-header">
        <div class="question-number-badge">
            <span class="question_number">@if(isset($question_index)){{$question_index + 1}}@endif</span>
        </div>
        <div class="question-header-text">
            السؤال رقم <span class="question_number">@if(isset($question_index)){{$question_index + 1}}@endif</span>
        </div>
    </div>

    <div class="question-section">
        <label class="question-label">
            <i class="fa fa-tag"></i>
            عنوان السؤال
        </label>
        {!! Form::text('q_title[]', isset($question) ? $question->title : null, ['class' => 'question-input', 'id'=>"title", 'placeholder' => 'أدخل عنوان السؤال'] )!!}
    </div>

    <div class="question-section">
        <label class="question-label">
            <i class="fa fa-image"></i>
            صورة السؤال (اختياري)
        </label>
        <div class="image-upload-section">
            <input type="file" name="q_question_image[]" class="form-control q_image admin-inline-hidden" id="q_image_{{isset($question_index) ? $question_index : 'new'}}">
            <label for="q_image_{{isset($question_index) ? $question_index : 'new'}}" class="question-upload-label">
                <i class="fa fa-cloud-upload question-upload-icon"></i>
                <p class="question-upload-copy">اضغط لاختيار صورة</p>
            </label>
            @if(isset($question) && $question->image)
                <img class="question-image-preview ico_cat" src="{{ $question->image }}" alt="صورة السؤال" />
            @else
                <img class="question-image-preview question-image-preview--empty ico_cat" src="" alt="صورة السؤال" />
            @endif
        </div>
    </div>

    <div class="question-section">
        <label class="question-label">
            <i class="fa fa-question-circle"></i>
            نص السؤال
        </label>
        {!! Form::textarea('q_question[]', isset($question) ? $question->question : '', ['class' => 'question-input', 'id'=>"q_question", 'rows' => 3, 'placeholder' => 'أدخل نص السؤال'] )!!}
    </div>

    <div class="question-section">
        <label class="question-label question-label--choices">
            <i class="fa fa-list-ul"></i>
            الاختيارات (اختر الإجابة الصحيحة)
        </label>

        <div class="choice-group">
            <div class="choice-radio-wrapper">
                <input type="radio" class="choice-radio" name="q_right_answer[@if(isset($question_index)){{$question_index}}@endif]" value="1" @if(isset($question) && $question->right_answer === 1) checked @endif />
            </div>
            <label class="choice-label">
                <i class="fa fa-circle-o"></i>
                اختيار 1
            </label>
            {!! Form::text('q_choice1[]', isset($question) ? $question->choice1 : null, ['class' => 'choice-input', 'id'=>"choice1", 'placeholder' => 'أدخل الاختيار الأول'] )!!}
        </div>

        <div class="choice-group">
            <div class="choice-radio-wrapper">
                <input type="radio" class="choice-radio" name="q_right_answer[@if(isset($question_index)){{$question_index}}@endif]" value="2" @if(isset($question) && $question->right_answer === 2) checked @endif />
            </div>
            <label class="choice-label">
                <i class="fa fa-circle-o"></i>
                اختيار 2
            </label>
            {!! Form::text('q_choice2[]', isset($question) ? $question->choice2 : null, ['class' => 'choice-input', 'id'=>"choice2", 'placeholder' => 'أدخل الاختيار الثاني'] )!!}
        </div>

        <div class="choice-group">
            <div class="choice-radio-wrapper">
                <input type="radio" class="choice-radio" name="q_right_answer[@if(isset($question_index)){{$question_index}}@endif]" value="3" @if(isset($question) && $question->right_answer === 3) checked @endif />
            </div>
            <label class="choice-label">
                <i class="fa fa-circle-o"></i>
                اختيار 3
            </label>
            {!! Form::text('q_choice3[]', isset($question) ? $question->choice3 : null, ['class' => 'choice-input', 'id'=>"choice3", 'placeholder' => 'أدخل الاختيار الثالث'] )!!}
        </div>

        <div class="choice-group">
            <div class="choice-radio-wrapper">
                <input type="radio" class="choice-radio" name="q_right_answer[@if(isset($question_index)){{$question_index}}@endif]" value="4" @if(isset($question) && $question->right_answer === 4) checked @endif />
            </div>
            <label class="choice-label">
                <i class="fa fa-circle-o"></i>
                اختيار 4
            </label>
            {!! Form::text('q_choice4[]', isset($question) ? $question->choice4 : null, ['class' => 'choice-input', 'id'=>"choice4", 'placeholder' => 'أدخل الاختيار الرابع'] )!!}
        </div>

        <div class="choice-group">
            <div class="choice-radio-wrapper">
                <input type="radio" class="choice-radio" name="q_right_answer[@if(isset($question_index)){{$question_index}}@endif]" value="5" @if(isset($question) && $question->right_answer === 5) checked @endif />
            </div>
            <label class="choice-label">
                <i class="fa fa-circle-o"></i>
                اختيار 5
            </label>
            {!! Form::text('q_choice5[]', isset($question) ? $question->choice5 : null, ['class' => 'choice-input', 'id'=>"choice5", 'placeholder' => 'أدخل الاختيار الخامس'] )!!}
        </div>

        <div class="choice-group">
            <div class="choice-radio-wrapper">
                <input type="radio" class="choice-radio" name="q_right_answer[@if(isset($question_index)){{$question_index}}@endif]" value="6" @if(isset($question) && $question->right_answer === 6) checked @endif />
            </div>
            <label class="choice-label">
                <i class="fa fa-circle-o"></i>
                اختيار 6
            </label>
            {!! Form::text('q_choice6[]', isset($question) ? $question->choice6 : null, ['class' => 'choice-input', 'id'=>"choice6", 'placeholder' => 'أدخل الاختيار السادس'] )!!}
        </div>
    </div>

    <div class="question-actions">
        <a href="#" class="remove_question btn-question-action btn-remove-question">
            <i class="fa fa-trash"></i>
            حذف السؤال
        </a>
        <a href="#" class="copy_question btn-question-action btn-copy-question">
            <i class="fa fa-copy"></i>
            نسخ السؤال
        </a>
    </div>
</div>

