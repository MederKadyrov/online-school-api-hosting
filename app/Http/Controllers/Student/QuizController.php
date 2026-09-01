<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\{Paragraph, Quiz, QuizAttempt, QuizAnswer, QuizQuestion, QuizOption, Grade};
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class QuizController extends Controller
{
    private function meStudentId(Request $r): int {
        $student = $r->user()->student;
        abort_unless($student, 403);
        return $student->id;
    }
    private function toFiveScale(float $score, float $max): int {
        if ($max <= 0) return 2;
        $pct = 100 * $score / $max;
        return $pct >= 90 ? 5 : ($pct >= 75 ? 4 : ($pct >= 60 ? 3 : 2));
    }

    public function getQuiz(Request $r, Paragraph $paragraph) {
        $quiz = $paragraph->quiz()->where('status','published')->first();
        if (!$quiz) return response()->json(null);

        $questions = $quiz->questions()
            ->orderBy('position')
            ->with(['options' => fn($q) => $q->orderBy('position')])
            ->get();

        if ($quiz->shuffle) {
            $questions = $questions->shuffle()->values();
            $questions->transform(function ($qq) {
                $qq->setRelation('options', $qq->options->shuffle()->values());
                return $qq;
            });
        }

        $payload = [
            'id'             => $quiz->id,
            'title'          => $quiz->title,
            'instructions'   => $quiz->instructions,
            'time_limit_sec' => $quiz->time_limit_sec,
            'max_attempts'   => $quiz->max_attempts,
            'shuffle'        => (bool) $quiz->shuffle,
            'max_points'     => $quiz->max_points,
            'questions'      => $questions->map(function($q){
                return [
                    'id'        => $q->id,
                    'type'      => $q->type,
                    'text'      => $q->text,
                    'image_url' => $q->image_url,
                    'points'    => (int) $q->points,
                    'position'  => (int) $q->position,
                    'options'  => in_array($q->type, ['single','multiple'])
                        ? $q->options->map(fn($o) => [
                            'id'       => $o->id,
                            'text'     => $o->text,
                            'position' => (int) $o->position,
                        ])->values()
                        : [],
                ];
            })->values(),
        ];

        return response()->json($payload);
    }

    public function start(Request $r, Quiz $quiz) {
        abort_unless($quiz->status==='published', 403);
        $sid = $this->meStudentId($r);

        // Check if deadline has passed (if deadline field exists)
        if (isset($quiz->due_at) && $quiz->due_at && now()->isAfter($quiz->due_at)) {
            abort(403, 'Quiz deadline has passed');
        }

        // лимит попыток
        if ($quiz->max_attempts) {
            $count = QuizAttempt::where('quiz_id',$quiz->id)->where('student_id',$sid)->count();
            abort_if($count >= $quiz->max_attempts, 403, 'Достигнут лимит попыток');
        }

        $attempt = QuizAttempt::create([
            'quiz_id'    => $quiz->id,
            'student_id' => $sid,
            'started_at' => now(),
            'status'     => 'in_progress',
            'score'      => 0,
        ]);
        return $attempt;
    }

    public function answer(Request $r, QuizAttempt $attempt) {
        $sid = $this->meStudentId($r);
        abort_unless($attempt->student_id === $sid, 403);
        abort_if($attempt->status !== 'in_progress', 403, 'Попытка завершена');

        $data = $r->validate([
            'question_id'        => 'required|integer|exists:quiz_questions,id',
            'selected_option_ids'=> 'nullable|array',
            'selected_option_ids.*'=> 'integer|exists:quiz_options,id',
            'text_answer'        => 'nullable|string',
        ]);

        $question = QuizQuestion::findOrFail($data['question_id']);
        abort_unless($question->quiz_id === $attempt->quiz_id, 422, 'Вопрос не из этого теста');

        $payload = [
            'selected_option_ids' => $question->type==='text' ? null : ($data['selected_option_ids'] ?? []),
            'text_answer'         => $question->type==='text' ? ($data['text_answer'] ?? null) : null,
        ];

        QuizAnswer::updateOrCreate(
            ['attempt_id'=>$attempt->id, 'question_id'=>$question->id],
            $payload
        );

        return ['message'=>'saved'];
    }

    public function finish(Request $r, QuizAttempt $attempt) {
        $sid = $this->meStudentId($r);
        abort_unless($attempt->student_id === $sid, 403);
        abort_if($attempt->status !== 'in_progress', 403);

        $quiz = $attempt->quiz()->with(['questions.options', 'paragraph.chapter.module'])->first();
        $answers = $attempt->answers()->get()->keyBy('question_id');

        $correct = 0;
        $wrong = 0;
        $unanswered = 0;

        DB::transaction(function() use ($r, $attempt, $quiz, $answers, &$correct, &$wrong, &$unanswered, $sid) {
            $score = 0;

            foreach ($quiz->questions as $q) {
                $ans = $answers->get($q->id);
                if (!$ans) {
                    if (in_array($q->type, ['single','multiple'])) {
                        $unanswered++;
                    }
                    continue;
                }

                if (in_array($q->type, ['single','multiple'])) {
                    $correctIds = $q->options->where('is_correct', true)->pluck('id')->sort()->values();
                    $selected = collect($ans->selected_option_ids ?? [])->sort()->values();
                    $isRight = $correctIds->count() > 0 && $correctIds->toJson() === $selected->toJson();
                    $auto = $isRight ? $q->points : 0;
                    $ans->auto_score = $auto;
                    $ans->save();
                    $score += $auto;

                    if ($selected->isEmpty()) {
                        $unanswered++;
                    } else {
                        $isRight ? $correct++ : $wrong++;
                    }
                } else {
                    $ans->auto_score = 0; // текст — вручную если потребуется
                    $ans->save();
                }
            }

            $attempt->score       = $score;
            $attempt->finished_at = now();
            $attempt->autograded  = ($quiz->questions->where('type','text')->count()===0);
            $attempt->status      = $attempt->autograded ? 'graded' : 'submitted';
            $attempt->save();

            // Создаем запись в таблице grades для этой попытки
            $courseId = $quiz->paragraph->chapter->module->course_id ?? null;
            $teacherId = $quiz->paragraph->chapter->module->course->teacher_id ?? null;

            if ($courseId) {
                // Вычисляем оценку по 5-бальной шкале
                $grade5 = $this->toFiveScale($score, max(1, $quiz->max_points));

                // Создаем новую оценку для каждой попытки (не updateOrCreate!)
                $grade = Grade::create([
                    'student_id' => $sid,
                    'course_id' => $courseId,
                    'teacher_id' => $teacherId,
                    'gradeable_type' => QuizAttempt::class,
                    'gradeable_id' => $attempt->id,
                    'grade_5' => $grade5,
                    'max_points' => $quiz->max_points,
                    'title' => $quiz->title,
                    'graded_at' => now(),
                ]);

                // Обновляем grade_id в попытке
                $attempt->grade_id = $grade->id;
                $attempt->save();
            }

            // Update student progress after quiz completion
            $paragraphId = $quiz->paragraph_id;
            if ($paragraphId) {
                $progressController = app(\App\Http\Controllers\Student\ProgressController::class);
                $progressController->updateProgress($r, \App\Models\Paragraph::find($paragraphId));
            }
        });

        $attempt->setAttribute('correct_count', $correct);
        $attempt->setAttribute('wrong_count', $wrong);
        $attempt->setAttribute('unanswered_count', $unanswered);
        return $attempt;
    }

    public function myAttempts(Request $r, Quiz $quiz) {
        $sid = $this->meStudentId($r);
        return $quiz->attempts()->where('student_id',$sid)->orderByDesc('id')->get();
    }

    public function allQuizzes(Request $r) {
        $sid = $this->meStudentId($r);
        $student = $r->user()->student;

        // Get student's group ID
        $groupId = $student->group_id;

        if (!$groupId) {
            return response()->json([]);
        }

        // Get all published quizzes from courses assigned to student's group
        $query = Quiz::with(['paragraph.chapter.module.course', 'attempts' => function($q) use ($sid) {
            $q->where('student_id', $sid)->orderByDesc('id');
        }])
        ->where('status', 'published')
        ->whereHas('paragraph.chapter.module.course', function($q) use ($groupId) {
            $q->whereHas('groups', function($q2) use ($groupId) {
                $q2->where('groups.id', $groupId);
            });
        });

        // Filter by course if provided
        if ($r->filled('course_id')) {
            $query->whereHas('paragraph.chapter.module', function($q) use ($r) {
                $q->where('course_id', $r->input('course_id'));
            });
        }

        // Filter by status if provided
        if ($r->filled('status')) {
            $status = $r->input('status');
            if ($status === 'pending') {
                $query->whereDoesntHave('attempts', function($q) use ($sid) {
                    $q->where('student_id', $sid)->whereIn('status', ['graded', 'submitted']);
                });
            } elseif ($status === 'submitted') {
                $query->whereHas('attempts', function($q) use ($sid) {
                    $q->where('student_id', $sid)->where('status', 'submitted');
                });
            } elseif ($status === 'graded') {
                $query->whereHas('attempts', function($q) use ($sid) {
                    $q->where('student_id', $sid)->where('status', 'graded');
                });
            }
        }

        $quizzes = $query->orderBy('id', 'desc')->get();

        // Format response
        $result = $quizzes->map(function($quiz) {
            // Get the latest graded or submitted attempt
            $attempt = $quiz->attempts->whereIn('status', ['graded', 'submitted'])->first();

            return [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->instructions,
                'deadline' => null, // Quizzes don't have deadlines by default
                'max_points' => $quiz->max_points,
                'paragraph_id' => $quiz->paragraph_id,
                'course_title' => $quiz->paragraph->chapter->module->course->title ?? '',
                'module_title' => $quiz->paragraph->chapter->module->title ?? '',
                'chapter_title' => $quiz->paragraph->chapter->title ?? '',
                'submission_status' => $attempt ? $attempt->status : null,
                'grade' => $attempt && $attempt->grade ? $attempt->grade->grade_5 : null,
            ];
        });

        return response()->json($result);
    }
}
