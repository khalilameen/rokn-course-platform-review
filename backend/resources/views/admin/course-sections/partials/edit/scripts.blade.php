@php
    $existingQuizQuestions = $section->getSectionType() === 'quiz' && $section->sectionable
        ? $section->sectionable->questions->map(fn ($question) => [
            'id' => $question->id,
            'title' => $question->title,
            'question' => $question->question,
            'choice1' => $question->choice1,
            'choice2' => $question->choice2,
            'choice3' => $question->choice3,
            'choice4' => $question->choice4,
            'choice5' => $question->choice5,
            'choice6' => $question->choice6,
            'right_answer' => $question->right_answer,
            'question_image' => $question->image,
        ])->values()
        : collect();
@endphp
<script>
// Existing quiz questions data
const existingQuestions = @json($existingQuizQuestions);
const flashedQuestions = @json(array_values(old('questions', [])));

document.addEventListener('DOMContentLoaded', function() {
    const visibleTitleAr = document.getElementById('title_ar');
    const visibleTitleEn = document.getElementById('title_en');
    const lessonTitleAr = document.getElementById('lesson_title_ar');
    const lessonTitleEn = document.getElementById('lesson_title_en');
    const syncLessonTitles = () => {
        if (lessonTitleAr && visibleTitleAr) lessonTitleAr.value = visibleTitleAr.value;
        if (lessonTitleEn && visibleTitleEn) lessonTitleEn.value = visibleTitleEn.value;
    };
    visibleTitleAr?.addEventListener('input', syncLessonTitles);
    visibleTitleEn?.addEventListener('input', syncLessonTitles);
    syncLessonTitles();
    const typeOptions = document.querySelectorAll('.type-option');
    const sectionTypeInput = document.getElementById('section_type');
    const dynamicForms = document.querySelectorAll('.dynamic-form');
    const currentSectionType = '{{ $section->getSectionType() }}';
    let questionCount = 0;
    let questionsLoaded = false;
    const questionsContainer = document.getElementById('questionsContainer');
    const addQuestionBtn = document.getElementById('addQuestionBtn');
    const existingQuestionsById = new Map(existingQuestions.map(question => [String(question.id), question]));
    const initialQuestions = (flashedQuestions.length > 0 ? flashedQuestions : existingQuestions).map(question => {
        const persisted = existingQuestionsById.get(String(question.id || '')) || {};
        return {
            ...question,
            question: question.question_text ?? question.question ?? '',
            right_answer: question.correct_answer ?? question.right_answer ?? '',
            question_image: question.question_image || persisted.question_image || '',
        };
    });

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);

    // Function to update required fields
    function updateRequiredFields(selectedType) {
        // Remove required attribute from all dynamic form fields
        dynamicForms.forEach(form => {
            const fields = form.querySelectorAll('[data-required="true"]');
            fields.forEach(field => {
                field.removeAttribute('required');
            });
        });

        // Add required attribute to selected form fields
        const activeForm = document.getElementById(`${selectedType}-form`);
        if (activeForm) {
            const fields = activeForm.querySelectorAll('[data-required="true"]');
            fields.forEach(field => {
                // Skip if the field's parent section is hidden (for video source toggle)
                const parentSection = field.closest('[id$="_section"], [id$="_video_section"]');
                if (parentSection && parentSection.style.display === 'none') {
                    return; // Skip this field
                }
                field.setAttribute('required', 'required');
            });
        }

        // Special handling for quiz questions
        if (!selectedType) {
            const currentActiveForm = document.querySelector('.dynamic-form.active');
            if (currentActiveForm && currentActiveForm.id === 'quiz-form') {
                const questionFields = document.querySelectorAll('#questionsContainer [data-required="true"]');
                questionFields.forEach(field => {
                    field.setAttribute('required', 'required');
                });
            }
        } else if (selectedType === 'quiz') {
            const questionFields = document.querySelectorAll('#questionsContainer [data-required="true"]');
            questionFields.forEach(field => {
                field.setAttribute('required', 'required');
            });
        }
    }

    // Handle type selection
    typeOptions.forEach(option => {
        option.addEventListener('click', function() {
            const type = this.dataset.type;

            // Update selected state
            typeOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');

            // Update hidden input
            sectionTypeInput.value = type;

            // Show corresponding form
            dynamicForms.forEach(form => {
                form.classList.remove('active');
                if (form.id === `${type}-form`) {
                    form.classList.add('active');

                    // Load existing questions when quiz form is shown (only if not already loaded)
                    if (type === 'quiz' && initialQuestions.length > 0 && !questionsLoaded) {
                        setTimeout(() => {
                            loadExistingQuestions();
                        }, 100);
                    }
                }
            });

            // Update required fields
            updateRequiredFields(type);
        });
    });

    // Set initial state - select current section type
    const oldType = '{{ old("section_type", $section->getSectionType()) }}';
    if (oldType) {
        const selectedOption = document.querySelector(`[data-type="${oldType}"]`);
        if (selectedOption) {
            selectedOption.click();
        }
    }

    // Form validation
    document.getElementById('sectionForm').addEventListener('submit', function(e) {
        const sectionType = sectionTypeInput.value;

        if (!sectionType) {
            e.preventDefault();
            alert('يرجى اختيار نوع المحتوى');
            return false;
        }

        // Special validation for quiz - check for at least one question
        if (sectionType === 'quiz') {
            const questions = document.querySelectorAll('#questionsContainer .question-item');
            if (questions.length === 0) {
                e.preventDefault();
                alert('يجب إضافة سؤال واحد على الأقل للاختبار');
                return false;
            }
        }

        // Validate required fields based on section type
        const activeForm = document.getElementById(`${sectionType}-form`);
        if (activeForm) {
            const requiredFields = activeForm.querySelectorAll('[required]');
            let isValid = true;
            let firstInvalidField = null;

            requiredFields.forEach(field => {
                const value = field.value.trim();
                if (!value) {
                    isValid = false;
                    field.style.borderColor = '#e53e3e';
                    if (!firstInvalidField) {
                        firstInvalidField = field;
                    }
                } else {
                    field.style.borderColor = '#e2e8f0';
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('يرجى ملء جميع الحقول المطلوبة');
                if (firstInvalidField) {
                    firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalidField.focus();
                }
                return false;
            }
        }
    });

    // Clear error styling on input
    document.querySelectorAll('.form-control, .form-select').forEach(field => {
        field.addEventListener('input', function() {
            if (this.value.trim()) {
                this.style.borderColor = '#e2e8f0';
            }
        });
    });

    // Toggle additional file links for lesson form
    const toggleFileLinksBtn = document.getElementById('toggleFileLinks');
    const additionalFileLinks = document.getElementById('additionalFileLinks');

    if (toggleFileLinksBtn && additionalFileLinks) {
        // Set initial button state
        if (!additionalFileLinks.classList.contains('is-hidden')) {
            toggleFileLinksBtn.innerHTML = '<i class="fa fa-minus-circle"></i> إخفاء روابط الملفات الإضافية';
            toggleFileLinksBtn.classList.add('is-active');
        }

        toggleFileLinksBtn.addEventListener('click', function() {
            if (additionalFileLinks.classList.contains('is-hidden')) {
                additionalFileLinks.classList.remove('is-hidden');
                this.innerHTML = '<i class="fa fa-minus-circle"></i> إخفاء روابط الملفات الإضافية';
                this.classList.add('is-active');
            } else {
                additionalFileLinks.classList.add('is-hidden');
                this.innerHTML = '<i class="fa fa-plus-circle"></i> إضافة روابط ملفات إضافية';
                this.classList.remove('is-active');
            }
        });
    }

    // Video Source Toggle (YouTube / Bunny)
    const videoSourceYoutube = document.getElementById('video_source_youtube');
    const videoSourceBunny = document.getElementById('video_source_bunny');
    const youtubeVideoSection = document.getElementById('youtube_video_section');
    const bunnyVideoSection = document.getElementById('bunny_video_section');
    const videoLinkInput = document.getElementById('video_link');

    function updateVideoSourceUI() {
        const youtubeLabel = document.getElementById('video_source_youtube_label');
        const bunnyLabel = document.getElementById('video_source_bunny_label');
        const bunnyVideoInput = document.getElementById('bunny_video');

        if (videoSourceYoutube && videoSourceYoutube.checked) {
            if (youtubeVideoSection) youtubeVideoSection.style.display = '';
            if (bunnyVideoSection) bunnyVideoSection.style.display = 'none';
            // Handle required attributes - remove from hidden fields
            if (videoLinkInput) {
                videoLinkInput.setAttribute('data-required', 'true');
                videoLinkInput.removeAttribute('required'); // Will be set by form validation
            }
            if (bunnyVideoInput) {
                bunnyVideoInput.removeAttribute('data-required');
                bunnyVideoInput.removeAttribute('required');
            }

            // Update label styles
            if (youtubeLabel) {
                youtubeLabel.style.borderColor = '#e53e3e';
                youtubeLabel.style.background = '#fff5f5';
            }
            if (bunnyLabel) {
                bunnyLabel.style.borderColor = '#e2e8f0';
                bunnyLabel.style.background = 'white';
            }
        } else if (videoSourceBunny && videoSourceBunny.checked) {
            if (youtubeVideoSection) youtubeVideoSection.style.display = 'none';
            if (bunnyVideoSection) bunnyVideoSection.style.display = '';
            // Handle required attributes - remove from hidden fields
            if (videoLinkInput) {
                videoLinkInput.removeAttribute('data-required');
                videoLinkInput.removeAttribute('required');
            }
            if (bunnyVideoInput) {
                // Don't set required on bunny_video for edit (existing video may be present)
                bunnyVideoInput.removeAttribute('required');
            }

            // Update label styles
            if (youtubeLabel) {
                youtubeLabel.style.borderColor = '#e2e8f0';
                youtubeLabel.style.background = 'white';
            }
            if (bunnyLabel) {
                bunnyLabel.style.borderColor = '#48bb78';
                bunnyLabel.style.background = '#f0fff4';
            }
        }
    }

    if (videoSourceYoutube) {
        videoSourceYoutube.addEventListener('change', function() {
            updateVideoSourceUI();
            // Re-run required fields update for lesson form
            if (sectionTypeInput.value === 'lesson') {
                updateRequiredFields('lesson');
            }
        });
    }
    if (videoSourceBunny) {
        videoSourceBunny.addEventListener('change', function() {
            updateVideoSourceUI();
            // Re-run required fields update for lesson form
            if (sectionTypeInput.value === 'lesson') {
                updateRequiredFields('lesson');
            }
        });
    }

    // Initialize on page load
    updateVideoSourceUI();

    // Quiz Questions Management
    function createQuestionTemplate(index, questionData = null) {
        const questionDiv = document.createElement('div');
        questionDiv.className = 'question-item mb-4';
        questionDiv.setAttribute('data-question-index', index);
        const questionText = escapeHtml(questionData?.question || '');
        const choice1 = escapeHtml(questionData?.choice1 || '');
        const choice2 = escapeHtml(questionData?.choice2 || '');
        const choice3 = escapeHtml(questionData?.choice3 || '');
        const choice4 = escapeHtml(questionData?.choice4 || '');
        const choice5 = escapeHtml(questionData?.choice5 || '');
        const choice6 = escapeHtml(questionData?.choice6 || '');
        const correctAnswer = questionData?.right_answer || '';
        const hasExtraChoices = choice5 || choice6;
        const questionImage = escapeHtml(questionData?.question_image || '');

        questionDiv.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 question-item__title">السؤال ${index + 1}</h5>
                <button type="button" class="remove-question-btn">
                    <i class="fa fa-trash"></i> حذف السؤال
                </button>
            </div>

            ${questionData?.id ? `<input type="hidden" name="questions[${index}][id]" value="${questionData.id}">` : ''}

            <div class="form-group mb-3">
                <label class="form-label" for="questions_${index}_text">نص السؤال *</label>
                <textarea id="questions_${index}_text" name="questions[${index}][question_text]" class="form-control" rows="3"
                          placeholder="أدخل نص السؤال" data-required="true">${questionText}</textarea>
            </div>

            <div class="form-group mb-3">
                <label class="form-label" for="questions_${index}_image">صورة توضيحية للسؤال</label>
                ${questionImage ? `<img src="${questionImage}" alt="صورة السؤال الحالية" class="question-image-preview img-fluid rounded d-block mb-2">` : ''}
                <input type="file" id="questions_${index}_image" name="questions[${index}][question_image]"
                       class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                <small class="text-muted">اتركها فارغة للاحتفاظ بالصورة الحالية</small>
            </div>

            <div class="row mb-2">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice1">الخيار الأول *</label>
                        <input type="text" id="questions_${index}_choice1" name="questions[${index}][choice1]" class="form-control"
                               placeholder="الخيار الأول" value="${choice1}" data-required="true">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice2">الخيار الثاني *</label>
                        <input type="text" id="questions_${index}_choice2" name="questions[${index}][choice2]" class="form-control"
                               placeholder="الخيار الثاني" value="${choice2}" data-required="true">
                    </div>
                </div>
            </div>

            <div class="row mb-2">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice3">الخيار الثالث *</label>
                        <input type="text" id="questions_${index}_choice3" name="questions[${index}][choice3]" class="form-control"
                               placeholder="الخيار الثالث" value="${choice3}" data-required="true">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice4">الخيار الرابع *</label>
                        <input type="text" id="questions_${index}_choice4" name="questions[${index}][choice4]" class="form-control"
                               placeholder="الخيار الرابع" value="${choice4}" data-required="true">
                    </div>
                </div>
            </div>

            <div class="extra-choices extra-choices-${index} ${hasExtraChoices ? '' : 'is-hidden'}">
                <div class="row mb-2">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="questions_${index}_choice5">الخيار الخامس</label>
                            <input type="text" id="questions_${index}_choice5" name="questions[${index}][choice5]" class="form-control"
                                   placeholder="الخيار الخامس" value="${choice5}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="questions_${index}_choice6">الخيار السادس</label>
                            <input type="text" id="questions_${index}_choice6" name="questions[${index}][choice6]" class="form-control"
                                   placeholder="الخيار السادس" value="${choice6}">
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="toggle-extra-choices-btn ${hasExtraChoices ? 'is-active' : ''} mb-3" data-question-index="${index}">
                <i class="fa fa-${hasExtraChoices ? 'minus' : 'plus'}-circle"></i> ${hasExtraChoices ? 'إخفاء الخيارات الإضافية' : 'إضافة خيارات إضافية (حتى 6)'}
            </button>

            <div class="form-group">
                <label class="form-label" for="questions_${index}_correct_answer">الإجابة الصحيحة *</label>
                <select id="questions_${index}_correct_answer" name="questions[${index}][correct_answer]" class="form-select correct-answer-select" data-required="true">
                    <option value="">اختر الإجابة الصحيحة</option>
                    <option value="1" ${correctAnswer == 1 ? 'selected' : ''}>الخيار الأول</option>
                    <option value="2" ${correctAnswer == 2 ? 'selected' : ''}>الخيار الثاني</option>
                    <option value="3" ${correctAnswer == 3 ? 'selected' : ''}>الخيار الثالث</option>
                    <option value="4" ${correctAnswer == 4 ? 'selected' : ''}>الخيار الرابع</option>
                    ${hasExtraChoices ? `
                    <option value="5" ${correctAnswer == 5 ? 'selected' : ''}>الخيار الخامس</option>
                    <option value="6" ${correctAnswer == 6 ? 'selected' : ''}>الخيار السادس</option>
                    ` : ''}
                </select>
            </div>
        `;

        return questionDiv;
    }

    function loadExistingQuestions() {
        // Prevent loading questions multiple times
        if (questionsLoaded || initialQuestions.length === 0) return;

        // Clear any existing questions in the container
        questionsContainer.innerHTML = '';
        questionCount = 0;

        initialQuestions.forEach((question, index) => {
            const questionElement = createQuestionTemplate(index, question);
            questionsContainer.appendChild(questionElement);
            setupQuestionEventListeners(questionElement, index);
            questionCount++;
        });

        questionsLoaded = true;
        updateRequiredFields();
    }

    function addQuestion() {
        const questionElement = createQuestionTemplate(questionCount);
        questionsContainer.appendChild(questionElement);
        setupQuestionEventListeners(questionElement, questionCount);

        // Set required attributes on question fields if quiz form is active
        const quizForm = document.getElementById('quiz-form');
        if (quizForm && quizForm.classList.contains('active')) {
            const requiredFields = questionElement.querySelectorAll('[data-required="true"]');
            requiredFields.forEach(field => {
                field.setAttribute('required', 'required');
            });
        }

        questionCount++;
        updateRequiredFields();
    }

    function setupQuestionEventListeners(questionElement, index) {
        const toggleBtn = questionElement.querySelector('.toggle-extra-choices-btn');
        const extraChoicesDiv = questionElement.querySelector(`.extra-choices-${index}`);
        const correctAnswerSelect = questionElement.querySelector('.correct-answer-select');

        toggleBtn.addEventListener('click', function() {
            if (extraChoicesDiv.classList.contains('is-hidden')) {
                extraChoicesDiv.classList.remove('is-hidden');
                this.innerHTML = '<i class="fa fa-minus-circle"></i> إخفاء الخيارات الإضافية';
                this.classList.add('is-active');

                // Add options 5 and 6 to correct answer select
                if (correctAnswerSelect.options.length < 7) {
                    const option5 = document.createElement('option');
                    option5.value = '5';
                    option5.textContent = 'الخيار الخامس';
                    correctAnswerSelect.appendChild(option5);

                    const option6 = document.createElement('option');
                    option6.value = '6';
                    option6.textContent = 'الخيار السادس';
                    correctAnswerSelect.appendChild(option6);
                }
            } else {
                extraChoicesDiv.classList.add('is-hidden');
                this.innerHTML = '<i class="fa fa-plus-circle"></i> إضافة خيارات إضافية (حتى 6)';
                this.classList.remove('is-active');

                // Remove options 5 and 6 from correct answer select
                while (correctAnswerSelect.options.length > 5) {
                    correctAnswerSelect.remove(correctAnswerSelect.options.length - 1);
                }

                // Clear choice5 and choice6 values
                const choice5 = questionElement.querySelector(`#questions_${toggleBtn.dataset.questionIndex}_choice5`);
                const choice6 = questionElement.querySelector(`#questions_${toggleBtn.dataset.questionIndex}_choice6`);
                if (choice5) choice5.value = '';
                if (choice6) choice6.value = '';

                // Reset correct answer if it was 5 or 6
                if (correctAnswerSelect.value === '5' || correctAnswerSelect.value === '6') {
                    correctAnswerSelect.value = '';
                }
            }
        });

        const removeBtn = questionElement.querySelector('.remove-question-btn');
        removeBtn.addEventListener('click', function() {
            questionElement.style.animation = 'shake 0.3s ease-in-out';
            setTimeout(() => {
                removeQuestion(questionElement);
            }, 300);
        });
    }

    function removeQuestion(questionElement) {
        questionElement.style.transition = 'all 0.4s ease-out';
        questionElement.style.opacity = '0';
        questionElement.style.transform = 'translateX(-20px) scale(0.95)';
        questionElement.style.maxHeight = questionElement.offsetHeight + 'px';

        setTimeout(() => {
            questionElement.style.maxHeight = '0';
            questionElement.style.padding = '0';
            questionElement.style.margin = '0';
            questionElement.style.border = 'none';
        }, 100);

        setTimeout(() => {
            questionElement.remove();
            reindexQuestions();
            updateRequiredFields();
        }, 500);
    }

    function reindexQuestions() {
        const questions = questionsContainer.querySelectorAll('.question-item');
        questionCount = 0;

        questions.forEach((questionDiv, index) => {
            questionDiv.setAttribute('data-question-index', index);

            const questionTitle = questionDiv.querySelector('h5');
            questionTitle.textContent = `السؤال ${index + 1}`;

            questionDiv.querySelectorAll('input, textarea, select').forEach(field => {
                const oldName = field.name;
                if (oldName && oldName.startsWith('questions[')) {
                    const fieldType = oldName.match(/\[([^\]]+)\]$/)[1];
                    field.name = `questions[${index}][${fieldType}]`;
                    field.id = `questions_${index}_${fieldType}`;
                }
            });

            questionDiv.querySelectorAll('label').forEach(label => {
                const forAttr = label.getAttribute('for');
                if (forAttr && forAttr.startsWith('questions_')) {
                    const fieldType = forAttr.split('_').pop();
                    label.setAttribute('for', `questions_${index}_${fieldType}`);
                }
            });

            const extraChoicesDiv = questionDiv.querySelector('.extra-choices');
            if (extraChoicesDiv) {
                [...extraChoicesDiv.classList]
                    .filter(className => className.startsWith('extra-choices-'))
                    .forEach(className => extraChoicesDiv.classList.remove(className));
                extraChoicesDiv.classList.add(`extra-choices-${index}`);
            }

            const toggleBtn = questionDiv.querySelector('.toggle-extra-choices-btn');
            if (toggleBtn) {
                toggleBtn.setAttribute('data-question-index', index);
            }

            questionCount++;
        });
    }

    // Add question button handler
    if (addQuestionBtn) {
        addQuestionBtn.addEventListener('click', addQuestion);
    }

    document.addEventListener('rokn:authoring-draft-prepare', event => {
        if (event.detail?.formId !== 'sectionForm') return;
        const values = event.detail?.values || {};
        const indexes = Object.keys(values)
            .map(name => name.match(/^questions\[(\d+)\]/))
            .filter(Boolean)
            .map(match => Number(match[1]));
        if (indexes.length === 0) return;

        // The generic draft restorer can only apply values after their dynamic
        // rows exist. Mark this source as loaded so the delayed database load
        // cannot overwrite the restored, newer moderator edits.
        if (!questionsLoaded) {
            questionsContainer.innerHTML = '';
            questionCount = 0;
            questionsLoaded = true;
        }
        const maxIndex = Math.max(...indexes);
        while (questionCount <= maxIndex) addQuestion();
    });

    // Load existing questions if this is a quiz section
    if (currentSectionType === 'quiz' && initialQuestions.length > 0) {
        setTimeout(() => {
            loadExistingQuestions();
        }, 100);
    }
});
</script>
