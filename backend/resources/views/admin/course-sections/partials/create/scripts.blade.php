<script>
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

        // Special handling for quiz questions (if selectedType is not provided, check current active form)
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

                    // Auto-add first question when quiz form is shown
                    if (type === 'quiz') {
                        const questionsContainer = document.getElementById('questionsContainer');
                        if (questionsContainer && questionsContainer.querySelectorAll('.question-item').length === 0) {
                            // Small delay to ensure DOM is ready
                            setTimeout(() => {
                                addQuestion();
                            }, 100);
                        }
                    }
                }
            });

            // Update required fields
            updateRequiredFields(type);
        });
    });

    // Set initial state from old input or URL parameter
    const oldType = '{{ old("section_type") }}';
    const urlParams = new URLSearchParams(window.location.search);
    const typeParam = urlParams.get('type');

    if (oldType) {
        const selectedOption = document.querySelector(`[data-type="${oldType}"]`);
        if (selectedOption) {
            selectedOption.click();
        }
    } else if (typeParam) {
        // Auto-select type from URL parameter
        const selectedOption = document.querySelector(`[data-type="${typeParam}"]`);
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

        // Special validation for quiz
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
                // Scroll to first invalid field
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
                bunnyVideoInput.setAttribute('data-required', 'true');
                bunnyVideoInput.removeAttribute('required'); // Will be set by form validation
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
    let questionCount = 0;
    const questionsContainer = document.getElementById('questionsContainer');
    const addQuestionBtn = document.getElementById('addQuestionBtn');

    // Function to validate quiz basic fields
    function validateQuizBasicFields() {
        const titleAr = document.getElementById('title_ar').value.trim();
        const quizTitleAr = document.getElementById('quiz_title_ar').value.trim();
        const timeMinutes = document.getElementById('time_minutes').value.trim();
        const moduleId = document.getElementById('module_id')?.value || '';

        if (!titleAr || !quizTitleAr || !timeMinutes || !moduleId) {
            return {
                valid: false,
                message: 'أكمل العنوان والوحدة ومدة الاختبار أولًا'
            };
        }

        return { valid: true };
    }

    // Function to validate current question fields
    function validateCurrentQuestion(index) {
        const questionText = document.getElementById(`questions_${index}_text`);
        const choice1 = document.getElementById(`questions_${index}_choice1`);
        const choice2 = document.getElementById(`questions_${index}_choice2`);
        const choice3 = document.getElementById(`questions_${index}_choice3`);
        const choice4 = document.getElementById(`questions_${index}_choice4`);
        const correctAnswer = document.getElementById(`questions_${index}_correct_answer`);

        const fields = [
            { field: questionText, name: 'نص السؤال' },
            { field: choice1, name: 'الخيار الأول' },
            { field: choice2, name: 'الخيار الثاني' },
            { field: choice3, name: 'الخيار الثالث' },
            { field: choice4, name: 'الخيار الرابع' },
            { field: correctAnswer, name: 'الإجابة الصحيحة' }
        ];

        for (let item of fields) {
            if (!item.field || !item.field.value.trim()) {
                return {
                    valid: false,
                    message: `يرجى ملء حقل "${item.name}" للسؤال ${index + 1} قبل إضافة سؤال جديد`,
                    field: item.field
                };
            }
        }

        return { valid: true };
    }

    function createQuestionTemplate(index, questionId = null) {
        const questionDiv = document.createElement('div');
        questionDiv.className = 'question-item mb-4';
        questionDiv.setAttribute('data-question-index', index);
        if (questionId) {
            questionDiv.setAttribute('data-question-id', questionId);
        }
        questionDiv.innerHTML = `
            <input type="hidden" name="questions[${index}][id]" value="${questionId || ''}" data-question-id-input>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 question-item__title">السؤال ${index + 1}</h5>
                ${index > 0 ? `
                    <button type="button" class="remove-question-btn">
                        <i class="fa fa-trash"></i> حذف السؤال
                    </button>
                ` : ''}
            </div>

            <div class="form-group mb-3">
                <label class="form-label" for="questions_${index}_text">نص السؤال *</label>
                <textarea id="questions_${index}_text" name="questions[${index}][question_text]" class="form-control" rows="3"
                          placeholder="أدخل نص السؤال" data-required="true"></textarea>
            </div>

            <div class="form-group mb-3">
                <label class="form-label" for="questions_${index}_image">صورة توضيحية للسؤال</label>
                <input type="file" id="questions_${index}_image" name="questions[${index}][question_image]"
                       class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                <small class="text-muted">اختيارية وتظهر فوق نص السؤال داخل الاختبار</small>
            </div>

            <div class="row mb-2">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice1">الخيار الأول *</label>
                        <input type="text" id="questions_${index}_choice1" name="questions[${index}][choice1]" class="form-control"
                               placeholder="الخيار الأول" data-required="true">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice2">الخيار الثاني *</label>
                        <input type="text" id="questions_${index}_choice2" name="questions[${index}][choice2]" class="form-control"
                               placeholder="الخيار الثاني" data-required="true">
                    </div>
                </div>
            </div>

            <div class="row mb-2">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice3">الخيار الثالث *</label>
                        <input type="text" id="questions_${index}_choice3" name="questions[${index}][choice3]" class="form-control"
                               placeholder="الخيار الثالث" data-required="true">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="questions_${index}_choice4">الخيار الرابع *</label>
                        <input type="text" id="questions_${index}_choice4" name="questions[${index}][choice4]" class="form-control"
                               placeholder="الخيار الرابع" data-required="true">
                    </div>
                </div>
            </div>

            <div class="extra-choices extra-choices-${index} is-hidden">
                <div class="row mb-2">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="questions_${index}_choice5">الخيار الخامس</label>
                            <input type="text" id="questions_${index}_choice5" name="questions[${index}][choice5]" class="form-control"
                                   placeholder="الخيار الخامس">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="questions_${index}_choice6">الخيار السادس</label>
                            <input type="text" id="questions_${index}_choice6" name="questions[${index}][choice6]" class="form-control"
                                   placeholder="الخيار السادس">
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="toggle-extra-choices-btn mb-3" data-question-index="${index}">
                <i class="fa fa-plus-circle"></i> إضافة خيارات إضافية (حتى 6)
            </button>

            <div class="form-group">
                <label class="form-label" for="questions_${index}_correct_answer">الإجابة الصحيحة *</label>
                <select id="questions_${index}_correct_answer" name="questions[${index}][correct_answer]" class="form-select correct-answer-select" data-required="true">
                    <option value="">اختر الإجابة الصحيحة</option>
                    <option value="1">الخيار الأول</option>
                    <option value="2">الخيار الثاني</option>
                    <option value="3">الخيار الثالث</option>
                    <option value="4">الخيار الرابع</option>
                </select>
            </div>
        `;

        return questionDiv;
    }

    function addQuestion() {
        const questionElement = createQuestionTemplate(questionCount);
        questionsContainer.appendChild(questionElement);

        // Set required attributes on question fields if quiz form is active
        const quizForm = document.getElementById('quiz-form');
        if (quizForm && quizForm.classList.contains('active')) {
            const requiredFields = questionElement.querySelectorAll('[data-required="true"]');
            requiredFields.forEach(field => {
                field.setAttribute('required', 'required');
            });
        }

        // Add event listener for toggle extra choices button
        const toggleBtn = questionElement.querySelector('.toggle-extra-choices-btn');
        const extraChoicesDiv = questionElement.querySelector(`.extra-choices-${questionCount}`);
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

                // Remove options 5 and 6 from correct answer select if they exist
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

        // Creating a quiz remains one atomic form submission. Removing a row
        // here never mutates the database or leaves an unfinished quiz behind.
        if (questionCount > 0) {
            const removeBtn = questionElement.querySelector('.remove-question-btn');
            removeBtn.addEventListener('click', function() {
                questionElement.style.animation = 'shake 0.3s ease-in-out';
                setTimeout(() => removeQuestion(questionElement), 300);
            });
        }

        questionCount++;
        updateRequiredFields();
    }

    function removeQuestion(questionElement) {
        // Add fade-out animation
        questionElement.style.transition = 'all 0.4s ease-out';
        questionElement.style.opacity = '0';
        questionElement.style.transform = 'translateX(-20px) scale(0.95)';
        questionElement.style.maxHeight = questionElement.offsetHeight + 'px';

        // Collapse the height after fade starts
        setTimeout(() => {
            questionElement.style.maxHeight = '0';
            questionElement.style.padding = '0';
            questionElement.style.margin = '0';
            questionElement.style.border = 'none';
        }, 100);

        // Remove element after animation completes
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

            // Update question number
            const questionTitle = questionDiv.querySelector('h5');
            questionTitle.textContent = `السؤال ${index + 1}`;

            // Update all field names and IDs
            questionDiv.querySelectorAll('input, textarea, select').forEach(field => {
                const oldName = field.name;
                if (oldName && oldName.startsWith('questions[')) {
                    const fieldType = oldName.match(/\[([^\]]+)\]$/)[1];
                    field.name = `questions[${index}][${fieldType}]`;
                    field.id = `questions_${index}_${fieldType}`;
                }
            });

            // Update labels
            questionDiv.querySelectorAll('label').forEach(label => {
                const forAttr = label.getAttribute('for');
                if (forAttr && forAttr.startsWith('questions_')) {
                    const fieldType = forAttr.split('_').pop();
                    label.setAttribute('for', `questions_${index}_${fieldType}`);
                }
            });

            // Update extra choices div class
            const extraChoicesDiv = questionDiv.querySelector('.extra-choices');
            if (extraChoicesDiv) {
                [...extraChoicesDiv.classList]
                    .filter(className => className.startsWith('extra-choices-'))
                    .forEach(className => extraChoicesDiv.classList.remove(className));
                extraChoicesDiv.classList.add(`extra-choices-${index}`);
            }

            // Update toggle button data attribute
            const toggleBtn = questionDiv.querySelector('.toggle-extra-choices-btn');
            if (toggleBtn) {
                toggleBtn.setAttribute('data-question-index', index);
            }

            questionCount++;
        });
    }

    // Questions stay in the recoverable browser draft until the moderator
    // saves the complete quiz in one database transaction.
    if (addQuestionBtn) {
        addQuestionBtn.addEventListener('click', function() {
            const existingQuestions = questionsContainer.querySelectorAll('.question-item');
            if (existingQuestions.length > 0) {
                const lastQuestionIndex = existingQuestions.length - 1;
                const validation = validateCurrentQuestion(lastQuestionIndex);
                if (!validation.valid) {
                    alert(validation.message);
                    if (validation.field) {
                        validation.field.focus();
                        validation.field.style.borderColor = '#e53e3e';
                        setTimeout(() => {
                            validation.field.style.borderColor = '#e2e8f0';
                        }, 2000);
                    }
                    return;
                }
            } else {
                const basicValidation = validateQuizBasicFields();
                if (!basicValidation.valid) {
                    alert(basicValidation.message);
                    return;
                }
            }
            addQuestion();
        });
    }

    // Validation redirects must preserve the quiz even when localStorage is
    // blocked by the browser. The server-flashed input is the fallback source.
    const oldQuestions = @json(array_values(old('questions', [])));
    oldQuestions.forEach(question => {
        addQuestion();
        const index = questionCount - 1;
        const row = questionsContainer.querySelector(`[data-question-index="${index}"]`);
        if (!row) return;
        if (question.choice5 || question.choice6) {
            row.querySelector('.toggle-extra-choices-btn')?.click();
        }
        Object.entries(question).forEach(([field, value]) => {
            const input = row.querySelector(`[name="questions[${index}][${CSS.escape(field)}]"]`);
            if (input) input.value = value ?? '';
        });
    });

    document.addEventListener('rokn:authoring-draft-prepare', event => {
        if (event.detail?.formId !== 'sectionForm') return;
        const values = event.detail?.values || {};
        const indexes = Object.keys(values)
            .map(name => name.match(/^questions\[(\d+)\]/))
            .filter(Boolean)
            .map(match => Number(match[1]));
        if (indexes.length === 0) return;
        const maxIndex = Math.max(...indexes);
        while (questionCount <= maxIndex) addQuestion();
    });
});
</script>
