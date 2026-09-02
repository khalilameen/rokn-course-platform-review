import {publicRequest} from '../../../constants/api';
import {firstBoolean} from '../../../services/api/common';
import type {CourseQuiz} from '../types';
import {asArray, asRecord, valueAsString} from './shared';
import {markSectionComplete} from './playback';

export type QuizQuestion = {
  id: string;
  title?: string;
  text: string;
  imageUrl?: string;
  choices: Array<{id: number; text: string}>;
};

export type QuizData = {
  questions: QuizQuestion[];
  passingScore: number;
};

export type QuizResult = {
  passed: boolean;
  scorePercentage: number;
  completionSynced: boolean;
};

export type QuizStart = {
  attemptId: string;
  answeredQuestions: number;
  answeredQuestionIds: string[];
};

const responseData = (value: unknown): Record<string, unknown> => {
  const root = asRecord(value);
  const first = asRecord(root.data);
  return asRecord(first.data || first);
};

const numericId = (value: string, field: string): number => {
  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed <= 0) {
    throw new Error(`INVALID_${field}_ID`);
  }
  return parsed;
};

export const loadCourseQuiz = async (
  courseId: string,
  quiz: CourseQuiz,
): Promise<QuizData> => {
  const normalizedCourseId = numericId(courseId, 'COURSE');
  const sectionId = numericId(quiz.sectionId, 'QUIZ_SECTION');
  const response = await publicRequest.get(
    `courses/${normalizedCourseId}/sections/${sectionId}/exam`,
  );
  const data = responseData(response.data);
  const metadata = asRecord(data.metadata);
  const questions = asArray<Record<string, unknown>>(data.questions).flatMap(
    raw => {
      const id = valueAsString(raw.id);
      const text = valueAsString(raw.question);
      const choices = asArray<Record<string, unknown>>(raw.choices)
        .map(choice => ({
          id: Number(choice.id),
          text: valueAsString(choice.text),
        }))
        .filter(
          choice =>
            Number.isInteger(choice.id) &&
            choice.id >= 1 &&
            choice.id <= 6 &&
            Boolean(choice.text),
        );
      if (!id || !text || choices.length < 2) return [];
      return [
        {
          id,
          title: valueAsString(raw.title) || undefined,
          text,
          imageUrl: valueAsString(raw.question_image) || undefined,
          choices,
        },
      ];
    },
  );
  if (!questions.length) throw new Error('QUIZ_EMPTY');
  return {
    questions,
    passingScore: Math.max(
      0,
      Math.min(100, Number(metadata.passing_score) || 60),
    ),
  };
};

export const startCourseQuiz = async (
  courseId: string,
  quiz: CourseQuiz,
): Promise<QuizStart> => {
  const quizId = numericId(quiz.id, 'QUIZ');
  const normalizedCourseId = numericId(courseId, 'COURSE');
  const sectionId = numericId(quiz.sectionId, 'QUIZ_SECTION');
  const response = await publicRequest.post('exams/start', {
    quiz_id: quizId,
    course_id: normalizedCourseId,
    section_id: sectionId,
  });
  const data = responseData(response.data);
  const attemptId = valueAsString(data.exam_attempt_id);
  if (!attemptId) throw new Error('QUIZ_START_FAILED');
  return {
    attemptId,
    answeredQuestions: Math.max(0, Number(data.answered_questions) || 0),
    answeredQuestionIds: asArray<unknown>(data.answered_question_ids)
      .map(value => valueAsString(value))
      .filter(Boolean),
  };
};

export const submitCourseQuizAnswer = async (
  attemptId: string,
  questionId: string,
  selectedAnswer: number,
): Promise<void> => {
  const normalizedAttemptId = numericId(attemptId, 'QUIZ_ATTEMPT');
  const normalizedQuestionId = numericId(questionId, 'QUIZ_QUESTION');
  if (!Number.isInteger(selectedAnswer) || selectedAnswer < 1) {
    throw new Error('INVALID_QUIZ_ANSWER');
  }
  try {
    await publicRequest.post('exams/submit-answer', {
      exam_attempt_id: normalizedAttemptId,
      question_id: normalizedQuestionId,
      selected_answer: selectedAnswer,
    });
  } catch (error) {
    const candidate = asRecord(error);
    const response = asRecord(candidate.response);
    const body = asRecord(response.data);
    const payload = asRecord(body.data);
    if (
      Number(response.status) === 409 &&
      valueAsString(payload.code) === 'quiz_answer_conflict'
    ) {
      // Another screen/device committed this question first. The stored
      // answer is immutable, so continuing is safer than trapping the learner
      // in a retry loop that can never replace it.
      return;
    }
    throw error;
  }
};

const quizResultFromData = (data: Record<string, unknown>): QuizResult => {
  const passed = firstBoolean(data.is_passed);
  const scorePercentage = Number(data.score_percentage);
  if (
    passed === undefined ||
    !Number.isFinite(scorePercentage) ||
    scorePercentage < 0 ||
    scorePercentage > 100
  ) {
    throw new Error('QUIZ_RESULT_INVALID');
  }
  return {passed, scorePercentage, completionSynced: false};
};

const syncQuizCompletion = async (
  courseId: string,
  quiz: CourseQuiz,
  result: QuizResult,
) => {
  if (result.passed) {
    result.completionSynced = await markSectionComplete(
      courseId,
      quiz.sectionId,
    );
  }
  return result;
};

export const loadCompletedCourseQuizResult = async (
  courseId: string,
  quiz: CourseQuiz,
  attemptId: string,
): Promise<QuizResult> => {
  const normalizedAttemptId = numericId(attemptId, 'QUIZ_ATTEMPT');
  const response = await publicRequest.get(
    `exams/${normalizedAttemptId}/results`,
  );
  return syncQuizCompletion(
    courseId,
    quiz,
    quizResultFromData(responseData(response.data)),
  );
};

export const finishCourseQuiz = async (
  courseId: string,
  quiz: CourseQuiz,
  attemptId: string,
): Promise<QuizResult> => {
  const normalizedAttemptId = numericId(attemptId, 'QUIZ_ATTEMPT');
  try {
    const response = await publicRequest.post('exams/end', {
      exam_attempt_id: normalizedAttemptId,
    });
    return syncQuizCompletion(
      courseId,
      quiz,
      quizResultFromData(responseData(response.data)),
    );
  } catch {
    // The commit may have succeeded while the response was lost. The result
    // endpoint is read-only and lets the same attempt recover without a
    // retake or a second grading transition.
    return loadCompletedCourseQuizResult(courseId, quiz, attemptId);
  }
};

export const completePassedCourseQuiz = (courseId: string, quiz: CourseQuiz) =>
  markSectionComplete(courseId, quiz.sectionId);
