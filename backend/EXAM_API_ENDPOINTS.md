# Exam API Endpoints Documentation

## Overview
This document provides comprehensive documentation for all exam and quiz-related API endpoints for the frontend application.

## Base URL
```
/api
```

## Authentication
All exam endpoints require authentication. Include the Bearer token in the Authorization header:
```
Authorization: Bearer {your_token}
```

---

## 📚 **1. Get Exam Data**

### **Get Exam Data by Quiz ID**
```http
GET /api/exams/{quizId}/data
```

**Description:** Retrieve exam/quiz data for a specific quiz ID (without right answers)

**Parameters:**
- `quizId` (path, required): The ID of the quiz/exam

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam data retrieved successfully",
  "data": {
    "id": 25,
    "title": "اختبار البرمجة الأساسية",
    "description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
    "type": "quiz",
    "image": "https://example.com/images/quiz-25.jpg",
    "is_opened": true,
    "time_minutes": 30,
    "questions_count": 10,
    "questions": [
      {
        "id": 101,
        "title": "ما هي أفضل لغة برمجة للمبتدئين؟",
        "question": "ما هي أفضل لغة برمجة للمبتدئين؟",
        "question_image": "https://example.com/images/programming-languages.jpg",
        "description": "اختر الإجابة الصحيحة",
        "choices": {
          "choice1": "Python",
          "choice2": "Java", 
          "choice3": "C++",
          "choice4": "JavaScript",
          "choice5": "PHP",
          "choice6": "Ruby"
        },
        "priority": 1
      }
    ],
    "metadata": {
      "total_questions": 10,
      "estimated_time": 30,
      "passing_score": 60,
      "max_score": 100
    },
    "instructions": {
      "time_limit": 30,
      "allow_review": true,
      "show_results": true,
      "randomize_questions": false,
      "randomize_choices": true
    },
    "created_at": "2024-01-15T10:30:00.000000Z",
    "updated_at": "2024-01-20T15:30:00.000000Z"
  }
}
```

### **Get Exam Data by Course Section**
```http
GET /api/courses/{courseId}/sections/{sectionId}/exam
```

**Description:** Retrieve exam data for a specific course section

**Parameters:**
- `courseId` (path, required): The ID of the course
- `sectionId` (path, required): The ID of the course section

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam data retrieved successfully",
  "data": {
    "section_id": 3,
    "section_title": "اختبار البرمجة الأساسية",
    "section_order": 3,
    "quiz_id": 25,
    "quiz_title": "اختبار البرمجة الأساسية",
    "quiz_description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
    "quiz_type": "quiz",
    "quiz_image": "https://example.com/images/quiz-25.jpg",
    "is_opened": true,
    "time_minutes": 30,
    "questions_count": 10,
    "questions": [...],
    "metadata": {...},
    "instructions": {...}
  }
}
```

---

## 🚀 **2. Start Exam**

### **Start New Exam Attempt**
```http
POST /api/exams/start
```

**Description:** Start a new exam attempt or resume an existing one

**Request Body:**
```json
{
  "quiz_id": 25,
  "course_id": 10,
  "section_id": 3
}
```

**Response (New Attempt):**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam started successfully",
  "data": {
    "exam_attempt_id": 123,
    "status": "in_progress",
    "started_at": "2024-01-20T10:30:00.000000Z",
    "total_questions": 10,
    "answered_questions": 0
  }
}
```

**Response (Resume Existing):**
```json
{
  "status": 200,
  "success": true,
  "message": "Resuming existing exam attempt",
  "data": {
    "exam_attempt_id": 123,
    "status": "in_progress",
    "started_at": "2024-01-20T10:30:00.000000Z",
    "answered_questions": 3,
    "total_questions": 10
  }
}
```

---

## 📝 **3. Submit Answer**

### **Submit Answer for Question**
```http
POST /api/exams/submit-answer
```

**Description:** Submit an answer for a specific question in an exam

**Request Body:**
```json
{
  "exam_attempt_id": 123,
  "question_id": 101,
  "selected_answer": 1
}
```

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Answer submitted successfully",
  "data": {
    "answer_id": 456,
    "is_correct": true,
    "answered_questions": 4,
    "total_questions": 10,
    "progress_percentage": 40.0
  }
}
```

---

## 📊 **4. Get Exam Progress**

### **Get Current Exam Progress**
```http
GET /api/exams/{examAttemptId}/progress
```

**Description:** Get the current progress of an exam attempt

**Parameters:**
- `examAttemptId` (path, required): The ID of the exam attempt

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam progress retrieved successfully",
  "data": {
    "exam_attempt_id": 123,
    "status": "in_progress",
    "started_at": "2024-01-20T10:30:00.000000Z",
    "total_questions": 10,
    "answered_questions": 4,
    "progress_percentage": 40.0,
    "answered_question_ids": [101, 102, 103, 104],
    "can_continue": true
  }
}
```

---

## ✅ **5. End Exam**

### **End Exam and Calculate Results**
```http
POST /api/exams/end
```

**Description:** End an exam attempt and calculate final results

**Request Body:**
```json
{
  "exam_attempt_id": 123
}
```

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam completed successfully",
  "data": {
    "exam_attempt_id": 123,
    "status": "completed",
    "completed_at": "2024-01-20T11:00:00.000000Z",
    "time_taken_minutes": 30,
    "total_questions": 10,
    "answered_questions": 10,
    "correct_answers": 8,
    "score_percentage": 80.0,
    "score_points": 80,
    "is_passed": true
  }
}
```

---

## 📋 **6. Get Exam History**

### **Get User's Exam History**
```http
GET /api/exams/history
```

**Description:** Get paginated list of user's completed exam attempts

**Query Parameters:**
- `per_page` (optional): Number of items per page (default: 15)
- `page` (optional): Page number (default: 1)

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam history retrieved successfully",
  "data": {
    "exams": [
      {
        "id": 123,
        "quiz_id": 25,
        "quiz_title": "اختبار البرمجة الأساسية",
        "quiz_description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
        "quiz_image": "https://example.com/images/quiz-25.jpg",
        "attempt_number": 1,
        "started_at": "2024-01-20T10:30:00.000000Z",
        "completed_at": "2024-01-20T11:00:00.000000Z",
        "time_taken_minutes": 30,
        "total_questions": 10,
        "answered_questions": 10,
        "correct_answers": 8,
        "score_percentage": 80.0,
        "score_points": 80,
        "is_passed": true
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 3,
      "per_page": 15,
      "total": 35,
      "from": 1,
      "to": 15
    }
  }
}
```

---

## 📈 **7. Get Exam Results**

### **Get Detailed Exam Results**
```http
GET /api/exams/{examAttemptId}/results
```

**Description:** Get detailed exam results with questions and answers

**Parameters:**
- `examAttemptId` (path, required): The ID of the exam attempt

**Response:**
```json
{
  "status": 200,
  "success": true,
  "message": "Exam results retrieved successfully",
  "data": {
    "exam_attempt_id": 123,
    "quiz_id": 25,
    "quiz_title": "اختبار البرمجة الأساسية",
    "quiz_description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
    "quiz_image": "https://example.com/images/quiz-25.jpg",
    "attempt_number": 1,
    "started_at": "2024-01-20T10:30:00.000000Z",
    "completed_at": "2024-01-20T11:00:00.000000Z",
    "time_taken_minutes": 30,
    "total_questions": 10,
    "answered_questions": 10,
    "correct_answers": 8,
    "score_percentage": 80.0,
    "score_points": 80,
    "is_passed": true,
    "questions": [
      {
        "question_id": 101,
        "title": "ما هي أفضل لغة برمجة للمبتدئين؟",
        "question": "ما هي أفضل لغة برمجة للمبتدئين؟",
        "question_image": "https://example.com/images/programming-languages.jpg",
        "description": "اختر الإجابة الصحيحة",
        "choices": {
          "choice1": "Python",
          "choice2": "Java",
          "choice3": "C++",
          "choice4": "JavaScript",
          "choice5": "PHP",
          "choice6": "Ruby"
        },
        "right_answer": 1,
        "priority": 1,
        "student_answer": 1,
        "is_correct": true,
        "points_earned": 10,
        "max_points": 10,
        "answered_at": "2024-01-20T10:35:00.000000Z"
      }
    ]
  }
}
```

---

## 🎲 **8. Random Quizzes**

### **Get All Random Quizzes**
```http
GET /api/random-quizzes
```

**Description:** Get list of all available random quizzes

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "اختبار عشوائي في البرمجة",
      "description": "اختبار عشوائي لقياس معرفتك في البرمجة",
      "created_at": "2024-01-15T10:30:00.000000Z",
      "updated_at": "2024-01-20T15:30:00.000000Z"
    }
  ]
}
```

### **Get Random Quiz Details**
```http
GET /api/random-quizzes/{randomQuiz}
```

**Description:** Get details of a specific random quiz

**Parameters:**
- `randomQuiz` (path, required): The ID of the random quiz

**Response:**
```json
{
  "data": {
    "id": 1,
    "title": "اختبار عشوائي في البرمجة",
    "description": "اختبار عشوائي لقياس معرفتك في البرمجة",
    "created_at": "2024-01-15T10:30:00.000000Z",
    "updated_at": "2024-01-20T15:30:00.000000Z"
  }
}
```

---

## 📚 **9. Get All Quizzes/Exams**

### **Get All Quizzes**
```http
GET /api/quizzes
```

**Description:** Get list of all available quizzes

**Response:**
```json
{
  "data": [
    {
      "id": 25,
      "title": "اختبار البرمجة الأساسية",
      "description": "اختبار شامل لقياس فهمك لأساسيات البرمجة",
      "type": "quiz",
      "image": "https://example.com/images/quiz-25.jpg",
      "created_at": "2024-01-15T10:30:00.000000Z",
      "updated_at": "2024-01-20T15:30:00.000000Z"
    }
  ]
}
```

### **Get All Exams**
```http
GET /api/exams
```

**Description:** Get list of all available exams (same as quizzes)

**Response:** Same as `/api/quizzes`

---

## 🚨 **Error Responses**

### **403 - Unauthorized**
```json
{
  "status": 403,
  "success": false,
  "message": "You are not authorized to access this exam. Please enroll in the course first.",
  "data": null
}
```

### **404 - Not Found**
```json
{
  "status": 404,
  "success": false,
  "message": "Exam attempt not found",
  "data": null
}
```

### **400 - Bad Request**
```json
{
  "status": 400,
  "success": false,
  "message": "Answer already submitted for this question",
  "data": null
}
```

### **500 - Server Error**
```json
{
  "status": 500,
  "success": false,
  "message": "Failed to start exam",
  "error": "Database connection error"
}
```

---

## 📱 **Frontend Integration Examples**

### **React Hook for Exam Management**
```javascript
import { useState, useEffect } from 'react';

const useExam = () => {
  const [examData, setExamData] = useState(null);
  const [examAttempt, setExamAttempt] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Start exam
  const startExam = async (quizId, courseId, sectionId) => {
    setLoading(true);
    try {
      const response = await fetch('/api/exams/start', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          quiz_id: quizId,
          course_id: courseId,
          section_id: sectionId
        })
      });
      
      const data = await response.json();
      if (data.success) {
        setExamAttempt(data.data);
        return data.data;
      } else {
        setError(data.message);
      }
    } catch (err) {
      setError('Failed to start exam');
    } finally {
      setLoading(false);
    }
  };

  // Submit answer
  const submitAnswer = async (examAttemptId, questionId, selectedAnswer) => {
    try {
      const response = await fetch('/api/exams/submit-answer', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          exam_attempt_id: examAttemptId,
          question_id: questionId,
          selected_answer: selectedAnswer
        })
      });
      
      const data = await response.json();
      if (data.success) {
        setExamAttempt(prev => ({
          ...prev,
          answered_questions: data.data.answered_questions,
          progress_percentage: data.data.progress_percentage
        }));
        return data.data;
      } else {
        setError(data.message);
      }
    } catch (err) {
      setError('Failed to submit answer');
    }
  };

  // End exam
  const endExam = async (examAttemptId) => {
    try {
      const response = await fetch('/api/exams/end', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          exam_attempt_id: examAttemptId
        })
      });
      
      const data = await response.json();
      if (data.success) {
        setExamAttempt(prev => ({
          ...prev,
          status: 'completed',
          ...data.data
        }));
        return data.data;
      } else {
        setError(data.message);
      }
    } catch (err) {
      setError('Failed to end exam');
    }
  };

  // Get exam data
  const getExamData = async (quizId) => {
    setLoading(true);
    try {
      const response = await fetch(`/api/exams/${quizId}/data`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      
      const data = await response.json();
      if (data.success) {
        setExamData(data.data);
        return data.data;
      } else {
        setError(data.message);
      }
    } catch (err) {
      setError('Failed to fetch exam data');
    } finally {
      setLoading(false);
    }
  };

  return {
    examData,
    examAttempt,
    loading,
    error,
    startExam,
    submitAnswer,
    endExam,
    getExamData
  };
};

export default useExam;
```

### **Exam Component Example**
```jsx
import React, { useState, useEffect } from 'react';
import useExam from './hooks/useExam';

const ExamComponent = ({ quizId, courseId, sectionId }) => {
  const { 
    examData, 
    examAttempt, 
    loading, 
    error, 
    startExam, 
    submitAnswer, 
    endExam, 
    getExamData 
  } = useExam();
  
  const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
  const [selectedAnswer, setSelectedAnswer] = useState(null);

  useEffect(() => {
    getExamData(quizId);
  }, [quizId]);

  const handleStartExam = async () => {
    await startExam(quizId, courseId, sectionId);
  };

  const handleSubmitAnswer = async () => {
    if (selectedAnswer && examAttempt) {
      await submitAnswer(examAttempt.exam_attempt_id, examData.questions[currentQuestionIndex].id, selectedAnswer);
      setSelectedAnswer(null);
      setCurrentQuestionIndex(prev => prev + 1);
    }
  };

  const handleEndExam = async () => {
    if (examAttempt) {
      await endExam(examAttempt.exam_attempt_id);
    }
  };

  if (loading) return <div>Loading...</div>;
  if (error) return <div>Error: {error}</div>;
  if (!examData) return <div>No exam data</div>;

  return (
    <div className="exam-container">
      {!examAttempt ? (
        <div className="exam-start">
          <h2>{examData.title}</h2>
          <p>{examData.description}</p>
          <p>Total Questions: {examData.questions_count}</p>
          <p>Estimated Time: {examData.metadata.estimated_time} minutes</p>
          <button onClick={handleStartExam}>Start Exam</button>
        </div>
      ) : examAttempt.status === 'in_progress' ? (
        <div className="exam-questions">
          <div className="progress-bar">
            <div 
              className="progress-fill" 
              style={{ width: `${examAttempt.progress_percentage}%` }}
            />
            <span>{examAttempt.answered_questions}/{examAttempt.total_questions}</span>
          </div>
          
          {currentQuestionIndex < examData.questions.length ? (
            <div className="question">
              <h3>{examData.questions[currentQuestionIndex].question}</h3>
              <div className="choices">
                {Object.entries(examData.questions[currentQuestionIndex].choices).map(([key, value]) => (
                  <label key={key}>
                    <input
                      type="radio"
                      name="answer"
                      value={key.replace('choice', '')}
                      checked={selectedAnswer === parseInt(key.replace('choice', ''))}
                      onChange={(e) => setSelectedAnswer(parseInt(e.target.value))}
                    />
                    {value}
                  </label>
                ))}
              </div>
              <button onClick={handleSubmitAnswer} disabled={!selectedAnswer}>
                Submit Answer
              </button>
            </div>
          ) : (
            <div className="exam-complete">
              <h3>Exam Complete!</h3>
              <button onClick={handleEndExam}>View Results</button>
            </div>
          )}
        </div>
      ) : (
        <div className="exam-results">
          <h3>Exam Results</h3>
          <p>Score: {examAttempt.score_percentage}%</p>
          <p>Correct Answers: {examAttempt.correct_answers}/{examAttempt.total_questions}</p>
          <p>Time Taken: {examAttempt.time_taken_minutes} minutes</p>
          <p>Status: {examAttempt.is_passed ? 'Passed' : 'Failed'}</p>
        </div>
      )}
    </div>
  );
};

export default ExamComponent;
```

---

## 🔄 **Complete Exam Flow**

1. **Get Exam Data** → `GET /api/exams/{quizId}/data`
2. **Start Exam** → `POST /api/exams/start`
3. **Submit Answers** → `POST /api/exams/submit-answer` (repeat for each question)
4. **Get Progress** → `GET /api/exams/{examAttemptId}/progress` (optional)
5. **End Exam** → `POST /api/exams/end`
6. **View Results** → `GET /api/exams/{examAttemptId}/results`

---

## 📊 **Data Models**

### **Exam Attempt Status**
- `in_progress`: Exam is currently being taken
- `completed`: Exam has been completed
- `abandoned`: Exam was started but not completed

### **Question Types**
- Multiple choice questions with 1-6 choices
- Each choice can contain text
- Questions can have images
- Questions have priority for ordering

### **Scoring System**
- Each question is worth 10 points
- Passing score is typically 60%
- Time tracking in minutes
- Attempt number tracking for retakes
