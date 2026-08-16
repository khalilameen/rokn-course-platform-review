# Course Details API Endpoint Sample

## Endpoint
```
GET /api/courses/{courseId}/details
```

## Sample Request
```
GET /api/courses/10/details
Authorization: Bearer {your_token}
```

## Sample Response (Enrolled User - Full Access)

```json
{
  "status": 200,
  "success": true,
  "message": "Course details retrieved successfully",
  "data": {
    "id": 10,
    "title": "دورة البرمجة المتقدمة",
    "title_en": "Advanced Programming Course",
    "type": "course",
    "description": "دورة شاملة لتعلم البرمجة المتقدمة وتطوير التطبيقات",
    "description_en": "Comprehensive course for learning advanced programming and app development",
    "image": "https://example.com/images/course-10.jpg",
    "price": 299.99,
    "price_before_discount": 399.99,
    "currency": "جنيه",
    "course_type": "online",
    "course_type_name": "أونلاين",
    
    "grade": {
      "id": 3,
      "name": "الصف الثالث الثانوي",
      "name_en": "Grade 12",
      "type": "secondary"
    },
    
    "groups": [
      {
        "id": 5,
        "name": "مجموعة البرمجة المتقدمة",
        "center_name": "مركز التطوير التقني"
      }
    ],
    
    "enrollment": {
      "id": 123,
      "enrolled_at": "2024-01-15T10:30:00.000000Z",
      "expires_at": "2024-12-31T23:59:59.000000Z",
      "is_active": true,
      "access_granted_at": "2024-01-15T10:30:00.000000Z"
    },
    
    "sections": [
      {
        "id": 1,
        "title": "مقدمة في البرمجة",
        "type": "lesson",
        "order": 1,
        "is_locked": false,
        "content": {
          "id": 15,
          "title": "مقدمة في البرمجة",
          "description": "تعلم أساسيات البرمجة والمفاهيم الأساسية",
          "video_link": "https://example.com/videos/intro-programming.mp4",
          "file_link1": "https://example.com/files/programming-basics.pdf",
          "file_link2": "https://example.com/files/exercises.pdf",
          "priority": 1,
          "is_opened": true
        }
      },
      {
        "id": 2,
        "title": "روابط مفيدة",
        "type": "link",
        "order": 2,
        "is_locked": false,
        "content": {
          "id": 8,
          "title": "روابط مفيدة للبرمجة",
          "description": "مجموعة من الروابط المفيدة لتعلم البرمجة",
          "title_en": "Useful Programming Links",
          "description_en": "Collection of useful links for learning programming",
          "link": "https://t.me/programming_resources",
          "type": "telegram"
        }
      },
      {
        "id": 3,
        "title": "اختبار البرمجة الأساسية",
        "type": "quiz",
        "order": 3,
        "is_locked": false,
        "content": {
          "id": 25,
          "title": "اختبار البرمجة الأساسية",
          "description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
          "type": "quiz",
          "priority": 1,
          "is_opened": true
        }
      },
      {
        "id": 4,
        "title": "دورة فرعية - هياكل البيانات",
        "type": "course",
        "order": 4,
        "is_locked": false,
        "content": {
          "id": 12,
          "title": "دورة هياكل البيانات",
          "description": "دورة متخصصة في هياكل البيانات والخوارزميات",
          "title_en": "Data Structures Course",
          "description_en": "Specialized course in data structures and algorithms",
          "image": "https://example.com/images/data-structures.jpg"
        }
      },
      {
        "id": 5,
        "title": "سؤال تفاعلي",
        "type": "question",
        "order": 5,
        "is_locked": false,
        "content": {
          "id": 33,
          "title": "ما هي أفضل لغة برمجة للمبتدئين؟",
          "description": "سؤال تفاعلي لاختبار معرفتك",
          "question": "ما هي أفضل لغة برمجة للمبتدئين؟",
          "question_image": "https://example.com/images/programming-languages.jpg",
          "choices": {
            "choice1": "Python",
            "choice2": "Java",
            "choice3": "C++",
            "choice4": "JavaScript",
            "choice5": "PHP",
            "choice6": "Ruby"
          },
          "right_answer": 1,
          "priority": 1
        }
      },
      {
        "id": 6,
        "title": "الدرس الثاني - المتغيرات",
        "type": "lesson",
        "order": 6,
        "is_locked": true,
        "content": {
          "id": 16,
          "title": "الدرس الثاني - المتغيرات",
          "description": "تعلم كيفية استخدام المتغيرات في البرمجة",
          "video_link": "https://example.com/videos/variables.mp4",
          "file_link1": "https://example.com/files/variables-guide.pdf",
          "file_link2": null,
          "priority": 2,
          "is_opened": true
        }
      }
    ],
    
    "metadata": {
      "video_count": 15,
      "hours_count": 40,
      "questions_count": 25,
      "exam_count": 3,
      "home_work_count": 10,
      "files_count": 20,
      "students_count": 150,
      "sections_count": 6
    },
    
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-20T15:30:00.000000Z"
  }
}
```

## Sample Response (Non-Enrolled User - Limited Access)

```json
{
  "status": 200,
  "success": true,
  "message": "Course details retrieved successfully",
  "data": {
    "id": 10,
    "title": "دورة البرمجة المتقدمة",
    "title_en": "Advanced Programming Course",
    "type": "course",
    "description": "دورة شاملة لتعلم البرمجة المتقدمة وتطوير التطبيقات",
    "description_en": "Comprehensive course for learning advanced programming and app development",
    "image": "https://example.com/images/course-10.jpg",
    "price": 299.99,
    "price_before_discount": 399.99,
    "currency": "جنيه",
    "course_type": "online",
    "course_type_name": "أونلاين",
    
    "grade": {
      "id": 3,
      "name": "الصف الثالث الثانوي",
      "name_en": "Grade 12",
      "type": "secondary"
    },
    
    "groups": [
      {
        "id": 5,
        "name": "مجموعة البرمجة المتقدمة",
        "center_name": "مركز التطوير التقني"
      }
    ],
    
    "sections": [
      {
        "id": 1,
        "title": "مقدمة في البرمجة",
        "type": "lesson",
        "order": 1,
        "content": {
          "id": 15,
          "title": "مقدمة في البرمجة",
          "description": "تعلم أساسيات البرمجة والمفاهيم الأساسية",
          "priority": 1,
          "is_opened": true
        }
      },
      {
        "id": 2,
        "title": "روابط مفيدة",
        "type": "link",
        "order": 2,
        "content": {
          "id": 8,
          "title": "روابط مفيدة للبرمجة",
          "description": "مجموعة من الروابط المفيدة لتعلم البرمجة",
          "title_en": "Useful Programming Links",
          "description_en": "Collection of useful links for learning programming",
          "type": "telegram"
        }
      },
      {
        "id": 3,
        "title": "اختبار البرمجة الأساسية",
        "type": "quiz",
        "order": 3,
        "content": {
          "id": 25,
          "title": "اختبار البرمجة الأساسية",
          "description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
          "type": "quiz",
          "priority": 1,
          "is_opened": true
        }
      },
      {
        "id": 4,
        "title": "دورة فرعية - هياكل البيانات",
        "type": "course",
        "order": 4,
        "content": {
          "id": 12,
          "title": "دورة هياكل البيانات",
          "description": "دورة متخصصة في هياكل البيانات والخوارزميات",
          "title_en": "Data Structures Course",
          "description_en": "Specialized course in data structures and algorithms",
          "image": "https://example.com/images/data-structures.jpg"
        }
      },
      {
        "id": 5,
        "title": "سؤال تفاعلي",
        "type": "question",
        "order": 5,
        "content": {
          "id": 33,
          "title": "ما هي أفضل لغة برمجة للمبتدئين؟",
          "description": "سؤال تفاعلي لاختبار معرفتك",
          "question": "ما هي أفضل لغة برمجة للمبتدئين؟",
          "question_image": "https://example.com/images/programming-languages.jpg",
          "priority": 1
        }
      }
    ],
    
    "metadata": {
      "video_count": 15,
      "hours_count": 40,
      "questions_count": 25,
      "exam_count": 3,
      "home_work_count": 10,
      "files_count": 20,
      "students_count": 150,
      "sections_count": 6
    },
    
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-20T15:30:00.000000Z"
  }
}
```

## Key Differences Between Enrolled vs Non-Enrolled Users

### Enrolled Users Get:
- ✅ **Enrollment information** (enrollment object)
- ✅ **Section lock status** (`is_locked` field)
- ✅ **Full content access** (video links, file links, quiz IDs, right answers)
- ✅ **Sensitive data** (telegram links, download URLs)

### Non-Enrolled Users Get:
- ❌ **No enrollment information**
- ❌ **No section lock status**
- ❌ **Limited content access** (no video links, file links, quiz IDs, right answers)
- ❌ **No sensitive data** (no telegram links, download URLs)

## Section Types Explained

### 1. **Lesson** (`type: "lesson"`)
- Contains video content, files, and learning materials
- Full access: video_link, file_link1, file_link2
- Limited access: basic info only

### 2. **Link** (`type: "link"`)
- Contains external links (Telegram groups, websites)
- Full access: actual link URL
- Limited access: link type only

### 3. **Quiz/Exam** (`type: "quiz"`)
- Contains exam/quiz content
- Full access: quiz ID for taking the exam
- Limited access: basic quiz info only

### 4. **Course** (`type: "course"`)
- Contains sub-course content
- Full access: complete course information
- Limited access: basic course info

### 5. **Question** (`type: "question"`)
- Contains interactive questions
- Full access: choices, right answers
- Limited access: question text only

## Usage in React Frontend

```javascript
// Fetch course details
const fetchCourseDetails = async (courseId) => {
  try {
    const response = await fetch(`/api/courses/${courseId}/details`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    });
    
    const data = await response.json();
    
    if (data.success) {
      const course = data.data;
      
      // Check if user is enrolled
      const isEnrolled = course.enrollment && course.enrollment.is_active;
      
      // Render sections based on enrollment status
      course.sections.forEach(section => {
        if (section.type === 'lesson' && isEnrolled) {
          // Show video player with section.content.video_link
        } else if (section.type === 'quiz' && isEnrolled) {
          // Show quiz button with section.content.id
        } else if (section.type === 'link' && isEnrolled) {
          // Show link button with section.content.link
        }
        
        // Check if section is locked
        if (section.is_locked) {
          // Show locked state
        }
      });
    }
  } catch (error) {
    console.error('Error fetching course details:', error);
  }
};
```

## Error Responses

### 403 - Not Authorized
```json
{
  "status": 403,
  "success": false,
  "message": "You are not authorized to access this course. Please purchase it first.",
  "data": null
}
```

### 404 - Course Not Found
```json
{
  "status": 404,
  "success": false,
  "message": "Course not found",
  "data": null
}
```

### 500 - Server Error
```json
{
  "status": 500,
  "success": false,
  "message": "Failed to fetch course details",
  "error": "Database connection error"
}
```
