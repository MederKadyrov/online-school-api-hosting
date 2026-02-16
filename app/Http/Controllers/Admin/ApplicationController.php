<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use App\Models\ApplicationReview;
use App\Models\User;
use App\Models\Student;
use App\Services\RegisterStudentService;
use App\Notifications\ApplicationApprovedNotification;
use App\Notifications\ApplicationRejectedNotification;
use App\Notifications\ApplicationRevisionRequestedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApplicationController extends Controller
{
    /**
     * Список всех заявок с фильтрацией
     */
    public function index(Request $request)
    {
        $query = StudentApplication::with(['reviewer'])
            ->orderBy('submitted_at', 'desc');

        // Фильтр по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Поиск по email или регистрационному номеру
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('registration_number', 'like', "%{$search}%")
                    ->orWhere('guardian_email', 'like', "%{$search}%")
                    ->orWhere('student_email', 'like', "%{$search}%");
            });
        }

        $applications = $query->paginate(20);

        return response()->json($applications);
    }

    /**
     * Просмотр конкретной заявки
     */
    public function show($id)
    {
        $application = StudentApplication::with(['reviewer', 'reviews.reviewer'])
            ->findOrFail($id);

        return response()->json($application);
    }

    /**
     * Одобрение заявки - создаёт пользователей и студента
     */
    public function approve(Request $request, $id, RegisterStudentService $service)
    {
        $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = StudentApplication::findOrFail($id);

        if (!$application->canBeReviewed()) {
            return response()->json([
                'message' => 'Эта заявка не может быть одобрена'
            ], 403);
        }

        DB::transaction(function () use ($application, $request, $service) {
            $appData = $application->application_data;

            // Проверяем, не существуют ли уже email в системе
            $guardianEmail = $appData['guardian']['email'] ?? null;
            $studentEmail = $appData['student']['email'] ?? null;

            if ($guardianEmail && User::where('email', $guardianEmail)->exists()) {
                throw new \Exception("Email опекуна {$guardianEmail} уже зарегистрирован в системе");
            }

            if ($studentEmail && User::where('email', $studentEmail)->exists()) {
                throw new \Exception("Email студента {$studentEmail} уже зарегистрирован в системе");
            }

            // Создаём пользователей и студента через RegisterStudentService
            $student = $service->handle(
                $appData['guardian'],
                $appData['student'],
                $appData['guardian_type']
            );

            // Сохраняем фото студента, если есть
            if (!empty($application->documents['student_photo'])) {
                $photoPath = str_replace('application_docs/', 'user_photos/', $application->documents['student_photo']);
                // Копируем файл
                Storage::disk('public')->put(
                    $photoPath,
                    Storage::disk('local')->get($application->documents['student_photo'])
                );
                $student->user->update(['photo' => $photoPath]);
            }

            // Переносим документы в student_documents
            if (!empty($application->documents)) {
                $doc = \App\Models\StudentDocument::firstOrCreate(['student_id' => $student->id]);
                $newDir = "student_docs/{$student->id}";

                $map = [
                    'guardian_application' => 'guardian_application_path',
                    'birth_certificate' => 'birth_certificate_path',
                    'student_pin_doc' => 'student_pin_doc_path',
                    'guardian_passport' => 'guardian_passport_path',
                    'medical_certificate' => 'medical_certificate_path',
                    'previous_school_record' => 'previous_school_record_path',
                ];

                foreach ($map as $key => $column) {
                    if (!empty($application->documents[$key])) {
                        $oldPath = $application->documents[$key];
                        $fileName = basename($oldPath);
                        $newPath = "{$newDir}/{$fileName}";

                        // Копируем файл
                        Storage::disk('local')->put(
                            $newPath,
                            Storage::disk('local')->get($oldPath)
                        );

                        $doc->{$column} = $newPath;
                    }
                }
                $doc->save();
            }

            // Обновляем заявку
            $application->update([
                'status' => 'approved',
                'guardian_user_id' => $student->guardian_id,
                'student_user_id' => $student->user_id,
                'student_id' => $student->id,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'admin_comment' => $request->comment,
            ]);

            // Добавляем запись в историю
            $application->reviews()->create([
                'reviewer_id' => $request->user()->id,
                'action' => 'approved',
                'comment' => $request->comment,
            ]);

            // Отправляем email-уведомление
            $guardianUser = User::find($student->guardian_id);
            if ($guardianUser && $guardianUser->email) {
                $guardianUser->notify(new ApplicationApprovedNotification($application));
            }

            // Также уведомляем студента, если у него есть email
            if ($student->user->email) {
                $student->user->notify(new ApplicationApprovedNotification($application));
            }
        });

        return response()->json([
            'message' => 'Заявка одобрена, пользователи созданы',
        ]);
    }

    /**
     * Отклонение заявки
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        $application = StudentApplication::findOrFail($id);

        if (!$application->canBeReviewed()) {
            return response()->json([
                'message' => 'Эта заявка не может быть отклонена'
            ], 403);
        }

        $application->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_comment' => $request->comment,
            'revision_token' => null,
        ]);

        // Добавляем запись в историю
        $application->reviews()->create([
            'reviewer_id' => $request->user()->id,
            'action' => 'rejected',
            'comment' => $request->comment,
        ]);

        // Отправляем email-уведомление опекуну
        if ($application->guardian_email) {
            \Illuminate\Support\Facades\Notification::route('mail', $application->guardian_email)
                ->notify(new ApplicationRejectedNotification($application));
        }

        return response()->json([
            'message' => 'Заявка отклонена',
        ]);
    }

    /**
     * Запрос на редактирование заявки
     */
    public function requestRevision(Request $request, $id)
    {
        $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        $application = StudentApplication::findOrFail($id);

        if (!$application->canBeReviewed()) {
            return response()->json([
                'message' => 'Для этой заявки нельзя запросить изменения'
            ], 403);
        }

        // Генерируем токен для редактирования
        $revisionToken = StudentApplication::generateRevisionToken();

        $application->update([
            'status' => 'needs_revision',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_comment' => $request->comment,
            'revision_token' => $revisionToken,
        ]);

        // Добавляем запись в историю
        $application->reviews()->create([
            'reviewer_id' => $request->user()->id,
            'action' => 'revision_requested',
            'comment' => $request->comment,
        ]);

        // Отправляем email-уведомление опекуну с токеном
        if ($application->guardian_email) {
            \Illuminate\Support\Facades\Notification::route('mail', $application->guardian_email)
                ->notify(new ApplicationRevisionRequestedNotification($application, $revisionToken));
        }

        return response()->json([
            'message' => 'Запрос на редактирование отправлен',
        ]);
    }

    /**
     * Просмотр документа из заявки (inline в браузере)
     */
    public function viewDocument($id, $documentKey)
    {
        $application = StudentApplication::findOrFail($id);

        if (empty($application->documents[$documentKey])) {
            return response()->json([
                'message' => 'Документ не найден'
            ], 404);
        }

        $path = $application->documents[$documentKey];

        if (!Storage::disk('local')->exists($path)) {
            return response()->json([
                'message' => 'Файл не найден'
            ], 404);
        }

        $file = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path);

        return response($file, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . basename($path) . '"');
    }

    /**
     * Скачивание документа из заявки
     */
    public function downloadDocument($id, $documentKey)
    {
        $application = StudentApplication::findOrFail($id);

        if (empty($application->documents[$documentKey])) {
            return response()->json([
                'message' => 'Документ не найден'
            ], 404);
        }

        $path = $application->documents[$documentKey];

        if (!Storage::disk('local')->exists($path)) {
            return response()->json([
                'message' => 'Файл не найден'
            ], 404);
        }

        return Storage::disk('local')->download($path);
    }
}
