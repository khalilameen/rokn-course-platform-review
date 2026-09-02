import React, {useLayoutEffect, useMemo, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Image,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import type {CourseQuiz} from './types';
import {
  completePassedCourseQuiz,
  finishCourseQuiz,
  loadCompletedCourseQuizResult,
  loadCourseQuiz,
  startCourseQuiz,
  submitCourseQuizAnswer,
  type QuizData,
  type QuizResult,
} from './courseLearning/quizzes';

export default function QuizTransition({
  courseId,
  quiz,
  moduleTitle,
  width,
  height,
  topInset = 0,
  bottomInset = 0,
  onPassed,
}: {
  courseId: string;
  quiz: CourseQuiz;
  moduleTitle: string;
  width: number;
  height: number;
  topInset?: number;
  bottomInset?: number;
  onPassed: () => Promise<void> | void;
}) {
  const navigation = useNavigation<RootNavigation>();
  const actionInFlightRef = useRef(false);
  const mountedRef = useRef(true);
  const generationRef = useRef(0);
  const [data, setData] = useState<QuizData | null>(null);
  const [attemptId, setAttemptId] = useState('');
  const [index, setIndex] = useState(0);
  const [selected, setSelected] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState<QuizResult | null>(
    quiz.passed
      ? {
          passed: true,
          scorePercentage: quiz.scorePercentage || 0,
          completionSynced: true,
        }
      : null,
  );
  const question = data?.questions[index];
  const progress = useMemo(
    () => (data?.questions.length ? (index + 1) / data.questions.length : 0),
    [data?.questions.length, index],
  );

  const ownsLifecycle = (generation: number) =>
    mountedRef.current && generationRef.current === generation;

  useLayoutEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      generationRef.current += 1;
      actionInFlightRef.current = false;
    };
  }, []);

  useLayoutEffect(() => {
    generationRef.current += 1;
    actionInFlightRef.current = false;
    setData(null);
    setAttemptId('');
    setIndex(0);
    setSelected(null);
    setBusy(false);
    setError('');
    setResult(
      quiz.passed
        ? {
            passed: true,
            scorePercentage: quiz.scorePercentage || 0,
            completionSynced: true,
          }
        : null,
    );
  }, [courseId, quiz.id, quiz.passed, quiz.scorePercentage, quiz.sectionId]);

  const begin = async () => {
    if (actionInFlightRef.current) return;
    const generation = generationRef.current;
    actionInFlightRef.current = true;
    setBusy(true);
    setError('');
    try {
      // Load the immutable questions before mutating attempt state. If the
      // connection drops between two parallel requests, a successful start
      // must not be orphaned merely because the read failed beside it.
      const quizData = await loadCourseQuiz(courseId, quiz);
      if (!ownsLifecycle(generation)) return;
      const started = await startCourseQuiz(courseId, quiz);
      if (!ownsLifecycle(generation)) return;
      const answered = new Set(started.answeredQuestionIds);
      const firstUnansweredIndex = quizData.questions.findIndex(
        item => !answered.has(item.id),
      );
      if (
        firstUnansweredIndex < 0 ||
        started.answeredQuestions >= quizData.questions.length
      ) {
        const resumedResult = await finishCourseQuiz(
          courseId,
          quiz,
          started.attemptId,
        );
        if (!ownsLifecycle(generation)) return;
        setData(null);
        setAttemptId('');
        setSelected(null);
        setResult(resumedResult);
        if (resumedResult.passed && resumedResult.completionSynced) {
          if (!ownsLifecycle(generation)) return;
          await onPassed();
        }
        return;
      }
      setData(quizData);
      setAttemptId(started.attemptId);
      setIndex(firstUnansweredIndex);
      setSelected(null);
      setResult(null);
    } catch {
      if (ownsLifecycle(generation)) {
        setError('تعذّر فتح الاختبار الآن');
      }
    } finally {
      if (ownsLifecycle(generation)) {
        actionInFlightRef.current = false;
        setBusy(false);
      }
    }
  };

  const answer = async () => {
    if (
      !question ||
      selected === null ||
      !attemptId ||
      actionInFlightRef.current
    ) {
      return;
    }
    const generation = generationRef.current;
    const submittedQuestion = question;
    const submittedAnswer = selected;
    const submittedAttemptId = attemptId;
    const isLastQuestion = index >= (data?.questions.length || 1) - 1;
    actionInFlightRef.current = true;
    setBusy(true);
    setError('');
    try {
      await submitCourseQuizAnswer(
        submittedAttemptId,
        submittedQuestion.id,
        submittedAnswer,
      );
      if (!ownsLifecycle(generation)) return;
      if (!isLastQuestion) {
        setIndex(current => current + 1);
        setSelected(null);
        return;
      }
      const nextResult = await finishCourseQuiz(
        courseId,
        quiz,
        submittedAttemptId,
      );
      if (!ownsLifecycle(generation)) return;
      setResult(nextResult);
      setData(null);
      setAttemptId('');
      if (nextResult.passed && nextResult.completionSynced) {
        if (!ownsLifecycle(generation)) return;
        await onPassed();
      }
    } catch {
      if (!ownsLifecycle(generation)) return;
      try {
        // A second device may finish the same attempt while this screen is on
        // any question. Recover the committed result before offering a retry.
        const recovered = await loadCompletedCourseQuizResult(
          courseId,
          quiz,
          submittedAttemptId,
        );
        if (!ownsLifecycle(generation)) return;
        setResult(recovered);
        setData(null);
        setAttemptId('');
        if (recovered.passed && recovered.completionSynced) {
          if (!ownsLifecycle(generation)) return;
          await onPassed();
        }
        return;
      } catch {
        // The answer or the end transition has not committed yet.
      }
      if (ownsLifecycle(generation)) {
        setError('لم تُحفظ الإجابة\nحاول مرة أخرى');
      }
    } finally {
      if (ownsLifecycle(generation)) {
        actionInFlightRef.current = false;
        setBusy(false);
      }
    }
  };

  const continueAfterPass = async () => {
    if (!result?.passed || actionInFlightRef.current) return;
    const generation = generationRef.current;
    actionInFlightRef.current = true;
    setBusy(true);
    setError('');
    try {
      const synced =
        result.completionSynced ||
        (await completePassedCourseQuiz(courseId, quiz));
      if (!ownsLifecycle(generation)) return;
      if (!synced) {
        setError('لم يفتح الجزء التالي بعد\nحاول مرة أخرى');
        return;
      }
      setResult(current =>
        current ? {...current, completionSynced: true} : current,
      );
      if (!ownsLifecycle(generation)) return;
      await onPassed();
    } catch {
      if (ownsLifecycle(generation)) {
        setError('لم يفتح الجزء التالي بعد\nحاول مرة أخرى');
      }
    } finally {
      if (ownsLifecycle(generation)) {
        actionInFlightRef.current = false;
        setBusy(false);
      }
    }
  };

  return (
    <View style={[styles.page, {width, height}]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="العودة"
        hitSlop={10}
        style={[styles.back, {top: topInset + 8}]}
        onPress={() => goBackOrHome(navigation)}>
        <Text style={styles.backText}>›</Text>
      </Pressable>
      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={[
          styles.content,
          {paddingTop: topInset + 58, paddingBottom: bottomInset + 32},
        ]}>
        <Text style={styles.eyebrow}>
          {formatArabicDisplayText(moduleTitle)}
        </Text>
        <Text accessibilityRole="header" style={styles.title}>
          {formatArabicDisplayText(quiz.title)}
        </Text>

        {result ? (
          <View style={styles.card}>
            <View
              style={[
                styles.resultMark,
                !result.passed && styles.resultMarkRetry,
              ]}>
              <Text style={styles.resultMarkText}>
                {result.passed ? '✓' : '↻'}
              </Text>
            </View>
            <Text style={styles.resultTitle}>
              {result.passed ? 'اجتزت الاختبار' : 'حاول مرة أخرى'}
            </Text>
            <Text style={styles.resultScore}>
              نتيجتك {formatArabicNumber(Math.round(result.scorePercentage))}٪
            </Text>
            {!!error && (
              <Text accessibilityRole="alert" style={styles.error}>
                {error}
              </Text>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityState={{busy, disabled: busy}}
              disabled={busy}
              style={[styles.primary, busy && styles.disabled]}
              onPress={result.passed ? continueAfterPass : begin}>
              {busy ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <Text style={styles.primaryText}>
                  {result.passed ? 'أكمل الكورس' : 'إعادة الاختبار'}
                </Text>
              )}
            </Pressable>
          </View>
        ) : question ? (
          <View style={styles.card}>
            <View style={styles.progressTrack}>
              <View
                style={[
                  styles.progressFill,
                  {width: `${Math.round(progress * 100)}%`},
                ]}
              />
            </View>
            <Text style={styles.counter}>
              {formatArabicNumber(index + 1)} من{' '}
              {formatArabicNumber(data?.questions.length || 0)}
            </Text>
            {!!question.imageUrl && (
              <Image
                accessibilityIgnoresInvertColors
                accessibilityLabel="صورة توضيحية للسؤال"
                resizeMode="contain"
                source={{uri: question.imageUrl}}
                style={styles.questionImage}
              />
            )}
            <Text style={styles.question}>
              {formatArabicDisplayText(question.text)}
            </Text>
            <View accessibilityRole="radiogroup" style={styles.choices}>
              {question.choices.map(choice => (
                <Pressable
                  key={choice.id}
                  accessibilityRole="radio"
                  accessibilityState={{
                    checked: selected === choice.id,
                    disabled: busy,
                  }}
                  disabled={busy}
                  onPress={() => setSelected(choice.id)}
                  style={[
                    styles.choice,
                    selected === choice.id && styles.choiceSelected,
                  ]}>
                  <Text
                    style={[
                      styles.choiceText,
                      selected === choice.id && styles.choiceTextSelected,
                    ]}>
                    {formatArabicDisplayText(choice.text)}
                  </Text>
                </Pressable>
              ))}
            </View>
            {!!error && (
              <Text accessibilityRole="alert" style={styles.error}>
                {error}
              </Text>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityState={{
                busy,
                disabled: selected === null || busy,
              }}
              disabled={selected === null || busy}
              style={[
                styles.primary,
                (selected === null || busy) && styles.disabled,
              ]}
              onPress={answer}>
              {busy ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <Text style={styles.primaryText}>
                  {index === (data?.questions.length || 1) - 1
                    ? 'إظهار النتيجة'
                    : 'السؤال التالي'}
                </Text>
              )}
            </Pressable>
          </View>
        ) : (
          <View style={styles.card}>
            <Text style={styles.intro}>
              اختبار قصير قبل الانتقال للوحدة التالية
            </Text>
            {!!quiz.description && (
              <Text style={styles.description}>
                {formatArabicDisplayText(quiz.description)}
              </Text>
            )}
            {!!quiz.timeMinutes && (
              <Text style={styles.meta}>
                {formatArabicNumber(quiz.timeMinutes)} دقائق
              </Text>
            )}
            {!!error && (
              <Text accessibilityRole="alert" style={styles.error}>
                {error}
              </Text>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityState={{busy, disabled: busy}}
              disabled={busy}
              style={[styles.primary, busy && styles.disabled]}
              onPress={begin}>
              {busy ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <Text style={styles.primaryText}>ابدأ الاختبار</Text>
              )}
            </Pressable>
          </View>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {backgroundColor: '#070B11'},
  content: {
    flexGrow: 1,
    width: '100%',
    maxWidth: 620,
    alignSelf: 'center',
    justifyContent: 'center',
    paddingHorizontal: 20,
  },
  back: {
    position: 'absolute',
    right: 18,
    zIndex: 5,
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.08)',
  },
  backText: {
    color: '#fff',
    fontSize: 31,
    lineHeight: 34,
    transform: [{rotate: '180deg'}],
  },
  eyebrow: {
    color: '#79A8F8',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    textAlign: 'right',
  },
  title: {
    color: '#fff',
    fontFamily: Fonts.bold,
    fontSize: 25,
    lineHeight: 36,
    textAlign: 'right',
    marginTop: 7,
    marginBottom: 20,
  },
  card: {
    borderRadius: 24,
    padding: 20,
    backgroundColor: '#111923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  intro: {
    color: '#fff',
    fontFamily: Fonts.bold,
    fontSize: 18,
    lineHeight: 29,
    textAlign: 'right',
  },
  description: {
    color: 'rgba(255,255,255,.62)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 23,
    textAlign: 'right',
    marginTop: 8,
  },
  meta: {
    color: '#91B6F8',
    fontFamily: Fonts.medium,
    fontSize: 12,
    textAlign: 'right',
    marginTop: 14,
  },
  progressTrack: {
    height: 5,
    borderRadius: 3,
    overflow: 'hidden',
    backgroundColor: 'rgba(255,255,255,.09)',
  },
  progressFill: {height: 5, borderRadius: 3, backgroundColor: '#337CE6'},
  counter: {
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.medium,
    fontSize: 11,
    textAlign: 'right',
    marginTop: 12,
  },
  questionImage: {
    width: '100%',
    height: 220,
    borderRadius: 14,
    backgroundColor: 'rgba(255,255,255,.04)',
    marginTop: 14,
  },
  question: {
    color: '#fff',
    fontFamily: Fonts.bold,
    fontSize: 18,
    lineHeight: 30,
    textAlign: 'right',
    marginTop: 10,
  },
  choices: {gap: 9, marginTop: 18},
  choice: {
    minHeight: 50,
    borderRadius: 15,
    justifyContent: 'center',
    paddingHorizontal: 15,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.1)',
    backgroundColor: 'rgba(255,255,255,.035)',
  },
  choiceSelected: {
    borderColor: '#5F9BF2',
    backgroundColor: 'rgba(45,111,222,.18)',
  },
  choiceText: {
    color: 'rgba(255,255,255,.74)',
    fontFamily: Fonts.medium,
    fontSize: 14,
    lineHeight: 22,
    textAlign: 'right',
  },
  choiceTextSelected: {color: '#fff'},
  primary: {
    minHeight: 52,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
    marginTop: 20,
  },
  primaryText: {color: '#fff', fontFamily: Fonts.bold, fontSize: 14},
  disabled: {opacity: 0.45},
  error: {
    color: '#FF9D9D',
    fontFamily: Fonts.medium,
    fontSize: 12,
    lineHeight: 20,
    textAlign: 'right',
    marginTop: 13,
  },
  resultMark: {
    width: 66,
    height: 66,
    borderRadius: 33,
    alignItems: 'center',
    justifyContent: 'center',
    alignSelf: 'center',
    backgroundColor: '#1E8B61',
  },
  resultMarkRetry: {backgroundColor: '#A56B22'},
  resultMarkText: {color: '#fff', fontFamily: Fonts.bold, fontSize: 31},
  resultTitle: {
    color: '#fff',
    fontFamily: Fonts.bold,
    fontSize: 21,
    textAlign: 'center',
    marginTop: 15,
  },
  resultScore: {
    color: 'rgba(255,255,255,.62)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    textAlign: 'center',
    marginTop: 6,
  },
});
