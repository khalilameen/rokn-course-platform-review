# Course Authorization System

## Overview
A comprehensive course authorization system has been implemented that allows students to purchase and access courses through multiple payment methods including online payments, course codes, and wallet transfers.

## System Components

### 1. Database Models

#### Order Model (`app/Models/Order.php`)
- **Purpose**: Handles course purchase orders
- **Key Fields**:
  - `id` - Primary key
  - `tenant_id` - Multi-tenant support
  - `user_id` - Reference to the student
  - `course_id` - Reference to the course being purchased
  - `course_code_id` - Reference to course code if used
  - `coupon_id` - Reference to coupon if used
  - `payment_method` - Type of payment (online, course_code, wallet)
  - `payment_screenshot` - Screenshot of payment proof
  - `amount` - Original course price
  - `discount_amount` - Amount discounted (coupon + course code)
  - `final_amount` - Final amount after discounts
  - `status` - Order status (pending, approved, rejected, cancelled)
  - `notes` - Additional notes
  - `approved_at`, `approved_by` - Approval tracking
  - `created_at`, `updated_at` - Timestamps

#### Bill Model (`app/Models/Bill.php`)
- **Purpose**: Generates bills for orders
- **Key Fields**:
  - `bill_number` - Unique bill identifier
  - `amount`, `tax_amount`, `total_amount` - Billing amounts
  - `payment_status` - Bill payment status
  - `due_date`, `paid_at` - Payment tracking

#### CourseEnrollment Model (`app/Models/CourseEnrollment.php`)
- **Purpose**: Tracks student course access
- **Key Fields**:
  - `user_id`, `course_id` - Student and course references
  - `order_id` - Reference to the order that granted access
  - `enrolled_at`, `expires_at` - Access period
  - `is_active`, `access_granted_at` - Access status

### 2. API Endpoints

#### Course Authorization (`POST /api/courses/authorize`)
- **Purpose**: Main endpoint for course purchase/authorization
- **Parameters**:
  - `course_id` (required) - ID of the course to purchase
  - `payment_method` (required) - online, course_code, or wallet
  - `course_code` (optional) - Course code for free access
  - `coupon_code` (optional) - Coupon for discount
  - `payment_screenshot` (optional) - Payment proof image
  - `notes` (optional) - Additional notes

#### My Enrollments (`GET /api/courses/my-enrollments`)
- **Purpose**: Get user's enrolled courses
- **Returns**: List of active course enrollments

#### Check Access (`POST /api/courses/check-access`)
- **Purpose**: Check if user has access to a specific course
- **Parameters**:
  - `course_id` (required) - Course ID to check access for

#### List Courses (`GET /api/courses/list`)
- **Purpose**: Get courses list with general data and section types
- **Parameters**:
  - `grade_id` (optional) - Filter by grade ID
  - `course_type` (optional) - Filter by course type (online, center, both)
  - `search` (optional) - Search in course titles
  - `per_page` (optional) - Number of items per page (default: 15)
- **Returns**: Paginated list of courses with section types

#### View Course Details (`GET /api/courses/{courseId}/details`)
- **Purpose**: Get detailed course information for authorized students
- **Authentication**: Required
- **Authorization**: User must be enrolled in the course
- **Returns**: Complete course details with all sections and content

#### Get Exam Data (`GET /api/exams/{quizId}/data`)
- **Purpose**: Get exam/quiz data for client-side use (without right answers)
- **Authentication**: Required
- **Authorization**: User must be enrolled in a course that contains this exam
- **Returns**: Exam data with questions and randomized choices (no correct answers)

#### Get Exam Data by Section (`GET /api/courses/{courseId}/sections/{sectionId}/exam`)
- **Purpose**: Get exam data for a specific course section
- **Authentication**: Required
- **Authorization**: User must be enrolled in the course
- **Returns**: Exam data with questions and randomized choices (no correct answers)

#### Start Exam (`POST /api/exams/start`)
- **Purpose**: Start a new exam attempt or resume existing one
- **Authentication**: Required
- **Authorization**: User must be enrolled in the course containing the exam
- **Parameters**: `quiz_id`, `course_id` (optional), `section_id` (optional)
- **Returns**: Exam attempt details with progress information

#### Submit Answer (`POST /api/exams/submit-answer`)
- **Purpose**: Submit an answer for a specific question
- **Authentication**: Required
- **Authorization**: User must own the exam attempt
- **Parameters**: `exam_attempt_id`, `question_id`, `selected_answer`
- **Returns**: Answer submission confirmation and progress update

#### Get Exam Progress (`GET /api/exams/{examAttemptId}/progress`)
- **Purpose**: Get current exam progress and answered questions
- **Authentication**: Required
- **Authorization**: User must own the exam attempt
- **Returns**: Progress details and list of answered question IDs

#### End Exam (`POST /api/exams/end`)
- **Purpose**: Complete exam and calculate final results
- **Authentication**: Required
- **Authorization**: User must own the exam attempt
- **Parameters**: `exam_attempt_id`
- **Returns**: Final exam results with score and pass/fail status

#### Get Exam History (`GET /api/exams/history`)
- **Purpose**: Get user's completed exam history
- **Authentication**: Required
- **Returns**: Paginated list of completed exams with scores

#### Get Exam Results (`GET /api/exams/{examAttemptId}/results`)
- **Purpose**: Get detailed exam results with questions, answers, and solutions
- **Authentication**: Required
- **Authorization**: User must own the exam attempt
- **Returns**: Complete exam results with correct answers and scoring details

### 3. Validation System

#### CourseAuthorizationRequest (`app/Http/Requests/API/CourseAuthorizationRequest.php`)
- **Comprehensive validation** for all input parameters
- **Course code validation**:
  - Checks if code exists and is active
  - Validates code expiration and usage limits
  - Ensures code matches the course
  - Prevents duplicate usage by same user

- **Coupon validation**:
  - Checks if coupon exists and is active
  - Validates expiration date
  - Prevents duplicate usage

- **Payment method validation**:
  - Requires screenshot for online/wallet payments
  - Ensures course code is provided when using course_code method

## Payment Methods

### 1. Online Payment
- **Process**: User uploads payment screenshot
- **Order Status**: Pending (requires admin approval)
- **Bill Status**: Pending
- **Access**: Granted after admin approval

### 2. Course Code
- **Process**: User provides valid course code
- **Order Status**: Auto-approved
- **Bill Status**: Paid (free)
- **Access**: Immediately granted
- **Validation**: Code must be valid, active, and match the course

### 3. Wallet Transfer
- **Process**: User uploads transfer screenshot
- **Order Status**: Pending (requires admin approval)
- **Bill Status**: Pending
- **Access**: Granted after admin approval

## Workflow

### Course Code Purchase Flow
1. User submits course authorization request with course code
2. System validates course code (exists, active, not expired, not used)
3. System creates order with status "approved"
4. System creates bill with status "paid"
5. System creates course enrollment with immediate access
6. System marks course code as used
7. User receives immediate access to course

### Online/Wallet Purchase Flow
1. User submits course authorization request with payment screenshot
2. System validates all inputs
3. System creates order with status "pending"
4. System creates bill with status "pending"
5. Admin reviews payment screenshot
6. Admin approves/rejects order
7. If approved: system creates enrollment and grants access
8. If rejected: user can resubmit with new payment proof

## API Response Examples

### Successful Course Code Authorization
```json
{
  "status": 200,
  "success": true,
  "message": "Course access granted successfully using course code",
  "data": {
    "order_id": 1,
    "bill_id": 1,
    "order_status": "approved",
    "bill_status": "paid",
    "amount": 150.00,
    "discount_amount": 150.00,
    "final_amount": 0.00,
    "enrollment_created": true
  }
}
```

### Pending Online Payment
```json
{
  "status": 200,
  "success": true,
  "message": "Order created successfully. Please wait for admin approval",
  "data": {
    "order_id": 2,
    "bill_id": 2,
    "order_status": "pending",
    "bill_status": "pending",
    "amount": 150.00,
    "discount_amount": 0.00,
    "final_amount": 150.00,
    "enrollment_created": false
  }
}
```

### My Enrollments Response
```json
{
  "status": 200,
  "success": true,
  "message": "Enrollments retrieved successfully",
  "data": [
    {
      "id": 1,
      "course": {
        "id": 1,
        "title": "دورة التأسيس",
        "title_en": "Foundation Course",
        "image": "https://example.com/course.jpg"
      },
      "enrolled_at": "2024-01-01T00:00:00.000000Z",
      "expires_at": null,
      "is_active": true,
      "access_granted_at": "2024-01-01T00:00:00.000000Z"
    }
  ]
}
```

### List Courses Response
```json
{
  "status": 200,
  "success": true,
  "message": "Courses retrieved successfully",
  "data": {
    "courses": [
      {
        "id": 1,
        "title": "دورة التأسيس",
        "title_en": "Foundation Course",
        "description": "دورة تأسيسية شاملة",
        "description_en": "Comprehensive foundation course",
        "image": "https://example.com/course.jpg",
        "price": 150.00,
        "price_before_discount": 200.00,
        "currency": "جنيه",
        "course_type": "online",
        "course_type_name": "أونلاين",
        "grade": {
          "id": 1,
          "name": "الصف الأول",
          "name_en": "Grade 1",
          "type": "preparatory"
        },
        "sections_count": 5,
        "sections": [
          {
            "id": 1,
            "title": "الدرس الأول",
            "type": "lesson",
            "order": 1
          },
          {
            "id": 2,
            "title": "اختبار قصير",
            "type": "quiz",
            "order": 2
          }
        ],
        "metadata": {
          "video_count": 10,
          "hours_count": 20,
          "questions_count": 50,
          "exam_count": 5,
          "home_work_count": 8,
          "files_count": 15,
          "students_count": 120
        },
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 5,
      "per_page": 15,
      "total": 75,
      "from": 1,
      "to": 15
    }
  }
}
```

### View Course Details Response
```json
{
  "status": 200,
  "success": true,
  "message": "Course details retrieved successfully",
  "data": {
    "id": 1,
    "title": "دورة التأسيس",
    "title_en": "Foundation Course",
    "description": "دورة تأسيسية شاملة",
    "description_en": "Comprehensive foundation course",
    "image": "https://example.com/course.jpg",
    "price": 150.00,
    "price_before_discount": 200.00,
    "currency": "جنيه",
    "course_type": "online",
    "course_type_name": "أونلاين",
    "grade": {
      "id": 1,
      "name": "الصف الأول",
      "name_en": "Grade 1",
      "type": "preparatory"
    },
    "groups": [
      {
        "id": 1,
        "name": "المجموعة الأولى",
        "center_name": "مركز التميز"
      }
    ],
    "sections": [
      {
        "id": 1,
        "title": "الدرس الأول",
        "type": "lesson",
        "order": 1,
        "content": {
          "id": 1,
          "title": "مقدمة في الرياضيات",
          "description": "شرح مفصل للمفاهيم الأساسية",
          "video_link": "https://example.com/video1.mp4",
          "file_link1": "https://example.com/file1.pdf",
          "file_link2": null,
          "priority": 1,
          "is_opened": true
        }
      },
      {
        "id": 2,
        "title": "اختبار قصير",
        "type": "quiz",
        "order": 2,
        "content": {
          "id": 1,
          "title": "اختبار الوحدة الأولى",
          "description": "اختبار شامل للوحدة الأولى",
          "type": "quiz",
          "priority": 1,
          "is_opened": true
        }
      }
    ],
    "metadata": {
      "video_count": 10,
      "hours_count": 20,
      "questions_count": 50,
      "exam_count": 5,
      "home_work_count": 8,
      "files_count": 15,
      "students_count": 120
    },
    "enrollment": {
      "id": 1,
      "enrolled_at": "2024-01-01T00:00:00.000000Z",
      "expires_at": null,
      "is_active": true,
      "access_granted_at": "2024-01-01T00:00:00.000000Z"
    },
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

### Get Exam Data Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam data retrieved successfully",
  "data": {
    "id": 1,
    "title": "اختبار الوحدة الأولى",
    "description": "اختبار شامل للوحدة الأولى في الرياضيات",
    "type": "quiz",
    "image": "https://example.com/quiz.jpg",
    "is_opened": true,
    "questions_count": 5,
    "questions": [
      {
        "id": 1,
        "title": "السؤال الأول",
        "question": "ما هو ناتج جمع 5 + 3؟",
        "question_image": null,
        "description": "اختر الإجابة الصحيحة",
        "choices": [
          {
            "id": 2,
            "text": "8"
          },
          {
            "id": 1,
            "text": "7"
          },
          {
            "id": 3,
            "text": "9"
          },
          {
            "id": 4,
            "text": "6"
          }
        ],
        "priority": 1
      },
      {
        "id": 2,
        "title": "السؤال الثاني",
        "question": "ما هو حاصل ضرب 4 × 6؟",
        "question_image": null,
        "description": "احسب الناتج",
        "choices": [
          {
            "id": 3,
            "text": "24"
          },
          {
            "id": 1,
            "text": "20"
          },
          {
            "id": 2,
            "text": "22"
          },
          {
            "id": 4,
            "text": "26"
          }
        ],
        "priority": 2
      }
    ],
    "metadata": {
      "total_questions": 5,
      "estimated_time": 10,
      "passing_score": 60,
      "max_score": 50
    },
    "instructions": {
      "time_limit": 10,
      "allow_review": true,
      "show_results": true,
      "randomize_questions": false,
      "randomize_choices": true
    },
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

### Get Exam Data by Section Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam data retrieved successfully",
  "data": {
    "section_id": 2,
    "section_title": "اختبار قصير",
    "section_order": 2,
    "quiz_id": 1,
    "quiz_title": "اختبار الوحدة الأولى",
    "quiz_description": "اختبار شامل للوحدة الأولى في الرياضيات",
    "quiz_type": "quiz",
    "quiz_image": "https://example.com/quiz.jpg",
    "is_opened": true,
    "questions_count": 5,
    "questions": [
      {
        "id": 1,
        "title": "السؤال الأول",
        "question": "ما هو ناتج جمع 5 + 3؟",
        "question_image": null,
        "description": "اختر الإجابة الصحيحة",
        "choices": [
          {
            "id": 2,
            "text": "8"
          },
          {
            "id": 1,
            "text": "7"
          },
          {
            "id": 3,
            "text": "9"
          },
          {
            "id": 4,
            "text": "6"
          }
        ],
        "priority": 1
      }
    ],
    "metadata": {
      "total_questions": 5,
      "estimated_time": 10,
      "passing_score": 60,
      "max_score": 50
    },
    "instructions": {
      "time_limit": 10,
      "allow_review": true,
      "show_results": true,
      "randomize_questions": false,
      "randomize_choices": true
    },
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

### Start Exam Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam started successfully",
  "data": {
    "exam_attempt_id": 1,
    "status": "in_progress",
    "started_at": "2024-01-01T10:00:00.000000Z",
    "total_questions": 5,
    "answered_questions": 0
  }
}
```

### Submit Answer Response
```json
{
  "status": 200,
  "success": true,
  "message": "Answer submitted successfully",
  "data": {
    "answer_id": 1,
    "is_correct": true,
    "answered_questions": 1,
    "total_questions": 5,
    "progress_percentage": 20.0
  }
}
```

### Get Exam Progress Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam progress retrieved successfully",
  "data": {
    "exam_attempt_id": 1,
    "status": "in_progress",
    "started_at": "2024-01-01T10:00:00.000000Z",
    "total_questions": 5,
    "answered_questions": 2,
    "progress_percentage": 40.0,
    "answered_question_ids": [1, 3],
    "can_continue": true
  }
}
```

### End Exam Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam completed successfully",
  "data": {
    "exam_attempt_id": 1,
    "status": "completed",
    "completed_at": "2024-01-01T10:30:00.000000Z",
    "time_taken_minutes": 30,
    "total_questions": 5,
    "answered_questions": 5,
    "correct_answers": 4,
    "score_percentage": 80.0,
    "score_points": 80.0,
    "is_passed": true
  }
}
```

### Get Exam History Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam history retrieved successfully",
  "data": {
    "exams": [
      {
        "id": 1,
        "quiz_id": 1,
        "quiz_title": "اختبار الوحدة الأولى",
        "quiz_description": "اختبار شامل للوحدة الأولى",
        "quiz_image": "https://example.com/quiz.jpg",
        "attempt_number": "1",
        "started_at": "2024-01-01T10:00:00.000000Z",
        "completed_at": "2024-01-01T10:30:00.000000Z",
        "time_taken_minutes": 30,
        "total_questions": 5,
        "answered_questions": 5,
        "correct_answers": 4,
        "score_percentage": 80.0,
        "score_points": 80.0,
        "is_passed": true
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1,
      "from": 1,
      "to": 1
    }
  }
}
```

### Get Exam Results Response
```json
{
  "status": 200,
  "success": true,
  "message": "Exam results retrieved successfully",
  "data": {
    "exam_attempt_id": 1,
    "quiz_id": 1,
    "quiz_title": "اختبار الوحدة الأولى",
    "quiz_description": "اختبار شامل للوحدة الأولى",
    "quiz_image": "https://example.com/quiz.jpg",
    "attempt_number": "1",
    "started_at": "2024-01-01T10:00:00.000000Z",
    "completed_at": "2024-01-01T10:30:00.000000Z",
    "time_taken_minutes": 30,
    "total_questions": 5,
    "answered_questions": 5,
    "correct_answers": 4,
    "score_percentage": 80.0,
    "score_points": 80.0,
    "is_passed": true,
    "questions": [
      {
        "question_id": 1,
        "title": "السؤال الأول",
        "question": "ما هو ناتج جمع 5 + 3؟",
        "question_image": null,
        "description": "اختر الإجابة الصحيحة",
        "choices": {
          "choice1": "7",
          "choice2": "8",
          "choice3": "9",
          "choice4": "6"
        },
        "right_answer": 2,
        "priority": 1,
        "student_answer": 2,
        "is_correct": true,
        "points_earned": 10,
        "max_points": 10,
        "answered_at": "2024-01-01T10:05:00.000000Z"
      }
    ]
  }
}
```

## Security Features

### 1. Input Validation
- Comprehensive validation for all inputs
- File upload validation (image files only, 2MB limit)
- SQL injection prevention through Eloquent ORM

### 2. Business Logic Validation
- Prevents duplicate enrollments
- Validates course codes and coupons
- Ensures proper payment method requirements

### 3. Transaction Safety
- Database transactions ensure data consistency
- Rollback on errors prevents partial data creation

### 4. Access Control
- Authentication required for all endpoints
- User can only access their own enrollments
- Course access validation prevents unauthorized access

## Admin Features

### Order Management
- View all pending orders
- Review payment screenshots
- Approve or reject orders
- Track order history

### Bill Management
- Automatic bill generation
- Payment status tracking
- Due date management

### Course Code Management
- Create and manage course codes
- Track code usage
- Set expiration dates and usage limits

## Integration Points

### 1. Course System
- Integrates with existing Course model
- Uses course pricing and metadata
- Maintains course relationships

### 2. Course Sections System
- **Morph Relations**: Course sections use polymorphic relationships
- **Section Types**:
  - `lesson` - Video lessons with files
  - `question` - Individual questions with choices
  - `link` - External links and resources
  - `quiz` - Quiz/Exam sections
  - `course` - Sub-courses within main course
- **Client-Side Handling**: Section types are returned to React app for appropriate rendering
- **Content Structure**: Each section type has specific content structure optimized for client-side processing

### 3. Exam/Quiz System
- **Security**: Right answers are never sent to client-side
- **Randomization**: Choice order is randomized for each request
- **Authorization**: Only enrolled students can access exams
- **Metadata**: Includes time limits, scoring, and instructions
- **Flexibility**: Supports both direct quiz access and course section access
- **Client-Ready**: Structured data optimized for React app integration

### 4. Exam Submission System
- **Session Management**: Tracks exam attempts with start/completion times
- **Answer Persistence**: Saves answers one by one to prevent data loss
- **Progress Tracking**: Real-time progress updates and answered question tracking
- **Resume Capability**: Students can continue exams after page refresh
- **Final Scoring**: Automatic score calculation with pass/fail determination
- **Result History**: Complete exam history with detailed results
- **Data Integrity**: Prevents duplicate answers and ensures exam completion

### 5. User System
- Uses existing User model
- Maintains user authentication
- Tracks user enrollments

### 6. Course Code System
- Integrates with CourseCode model
- Validates and tracks code usage
- Maintains code relationships

### 7. Coupon System
- Integrates with Coupon model
- Applies discounts to orders
- Tracks coupon usage

## Usage Examples

### Using Course Code
```bash
curl -X POST /api/courses/authorize \
  -H "Authorization: Bearer {token}" \
  -F "course_id=1" \
  -F "payment_method=course_code" \
  -F "course_code=COURSE123"
```

### Online Payment
```bash
curl -X POST /api/courses/authorize \
  -H "Authorization: Bearer {token}" \
  -F "course_id=1" \
  -F "payment_method=online" \
  -F "payment_screenshot=@payment.jpg" \
  -F "coupon_code=SAVE20"
```

### Check Access
```bash
curl -X POST /api/courses/check-access \
  -H "Authorization: Bearer {token}" \
  -F "course_id=1"
```

### List Courses
```bash
curl -X GET "/api/courses/list?grade_id=1&course_type=online&search=math&per_page=10" \
  -H "Authorization: Bearer {token}"
```

### View Course Details
```bash
curl -X GET /api/courses/1/details \
  -H "Authorization: Bearer {token}"
```

### Get Exam Data
```bash
curl -X GET /api/exams/1/data \
  -H "Authorization: Bearer {token}"
```

### Get Exam Data by Section
```bash
curl -X GET /api/courses/1/sections/2/exam \
  -H "Authorization: Bearer {token}"
```

### Start Exam
```bash
curl -X POST /api/exams/start \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "quiz_id": 1,
    "course_id": 1,
    "section_id": 2
  }'
```

### Submit Answer
```bash
curl -X POST /api/exams/submit-answer \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "exam_attempt_id": 1,
    "question_id": 1,
    "selected_answer": 2
  }'
```

### Get Exam Progress
```bash
curl -X GET /api/exams/1/progress \
  -H "Authorization: Bearer {token}"
```

### End Exam
```bash
curl -X POST /api/exams/end \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "exam_attempt_id": 1
  }'
```

### Get Exam History
```bash
curl -X GET /api/exams/history \
  -H "Authorization: Bearer {token}"
```

### Get Exam Results
```bash
curl -X GET /api/exams/1/results \
  -H "Authorization: Bearer {token}"
```

## Migration Status
- ✅ Orders table updated with course fields
- ✅ Orders table cleaned up (removed unnecessary columns)
- ✅ Bills table exists (already present)
- ✅ Course enrollments table exists (already present)
- ✅ All models created and functional
- ✅ API endpoints implemented
- ✅ Validation system complete
- ✅ Business logic implemented
- ✅ Course listing endpoint implemented
- ✅ Course details endpoint implemented
- ✅ Exam data endpoints implemented
- ✅ Exam submission system implemented

## Table Cleanup Summary

### Orders Table Cleanup
The orders table has been cleaned up to remove unnecessary columns that were specific to the original e-commerce system. The following columns were removed:

**Removed Columns:**
- `provider_id` - Not needed for course orders
- `store_id`, `store_name` - Not needed for course orders
- `tax`, `sub_total` - Simplified to use amount/discount_amount/final_amount
- `paid` - Replaced with status enum
- `order_note` - Renamed to notes for consistency
- `status_id` - Replaced with status enum
- `type` - Not needed for course orders
- `service_id` - Not needed for course orders
- `client_lat`, `client_lng`, `delivering_lat`, `delivering_lng` - Not needed for course orders
- `coupon_code` - Using coupon_id relationship instead
- `discount` - Replaced with discount_amount
- `payment_type` - Replaced with payment_method enum
- `delivery_time_id` - Not needed for course orders
- `finish_at`, `cancelled_at` - Using status enum instead
- `total` - Replaced with final_amount

**Kept Columns:**
- `id` - Primary key
- `tenant_id` - Multi-tenant support
- `user_id` - Student reference
- `course_id` - Course reference
- `course_code_id` - Course code reference
- `coupon_id` - Coupon reference
- `payment_method` - Payment method enum
- `payment_screenshot` - Payment proof
- `amount` - Original price
- `discount_amount` - Discount amount
- `final_amount` - Final price after discounts
- `status` - Order status enum
- `notes` - Additional notes
- `approved_at`, `approved_by` - Approval tracking
- `created_at`, `updated_at` - Timestamps

## Next Steps
The course authorization system is now fully functional and ready for use. Students can:
1. Purchase courses using course codes (immediate access)
2. Purchase courses using online payments (pending approval)
3. Purchase courses using wallet transfers (pending approval)
4. View their enrolled courses
5. Check access to specific courses

The system provides a complete solution for course monetization and access control while maintaining security and data integrity.
