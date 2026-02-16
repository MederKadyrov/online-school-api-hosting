<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApplicationStatusController extends Controller
{
    /**
     * Проверка статуса заявки по регистрационному номеру
     */
    public function checkStatus(Request $request)
    {
        $request->validate([
            'registration_number' => ['required', 'string', 'max:20'],
        ]);

        $application = StudentApplication::where('registration_number', $request->registration_number)->first();

        if (!$application) {
            return response()->json([
                'message' => 'Заявка с таким регистрационным номером не найдена'
            ], 404);
        }

        return response()->json([
            'registration_number' => $application->registration_number,
            'status' => $application->status,
            'submitted_at' => $application->submitted_at,
            'reviewed_at' => $application->reviewed_at,
            'admin_comment' => $application->admin_comment,
            'can_revise' => $application->canBeRevised(),
            'revision_token' => $application->canBeRevised() ? $application->revision_token : null,
        ]);
    }

    /**
     * Получение данных заявки для редактирования
     */
    public function getRevisionData(Request $request)
    {
        $request->validate([
            'registration_number' => ['required', 'string', 'max:20'],
            'revision_token' => ['required', 'string'],
        ]);

        $application = StudentApplication::where('registration_number', $request->registration_number)
            ->where('revision_token', $request->revision_token)
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Неверный регистрационный номер или токен'
            ], 404);
        }

        if (!$application->canBeRevised()) {
            return response()->json([
                'message' => 'Эта заявка не может быть отредактирована'
            ], 403);
        }

        return response()->json([
            'registration_number' => $application->registration_number,
            'admin_comment' => $application->admin_comment,
            'documents' => $application->documents ?? [],
            'status' => $application->status,
        ]);
    }

    /**
     * Просмотр документа из заявки на ревизию
     */
    public function viewRevisionDocument(Request $request, $documentKey)
    {
        // Валидация query параметров
        $validated = $request->validate([
            'registration_number' => ['required', 'string', 'max:20'],
            'revision_token' => ['required', 'string'],
        ]);

        $application = StudentApplication::where('registration_number', $validated['registration_number'])
            ->where('revision_token', $validated['revision_token'])
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Неверный регистрационный номер или токен'
            ], 404);
        }

        if (!$application->canBeRevised()) {
            return response()->json([
                'message' => 'Эта заявка не может быть отредактирована'
            ], 403);
        }

        $documents = $application->documents ?? [];

        if (!isset($documents[$documentKey])) {
            return response()->json([
                'message' => 'Документ не найден'
            ], 404);
        }

        $path = $documents[$documentKey];

        if (!Storage::disk('local')->exists($path)) {
            return response()->json([
                'message' => 'Файл не найден на сервере'
            ], 404);
        }

        $file = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path);

        return response($file, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . basename($path) . '"');
    }

    /**
     * Редактирование заявки (повторная отправка документов)
     */
    public function revise(Request $request)
    {
        $request->validate([
            'registration_number' => ['required', 'string', 'max:20'],
            'revision_token' => ['required', 'string'],
            'student_photo' => ['nullable', 'file', 'mimes:jpeg,jpg,png', 'max:4096'],
            'guardian_application' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:8192'],
            'birth_certificate' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:8192'],
            'student_pin_doc' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:8192'],
            'guardian_passport' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:8192'],
            'medical_certificate' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:8192'],
            'previous_school_record' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:8192'],
        ]);

        $application = StudentApplication::where('registration_number', $request->registration_number)
            ->where('revision_token', $request->revision_token)
            ->first();

        if (!$application) {
            return response()->json([
                'message' => 'Неверный регистрационный номер или токен'
            ], 404);
        }

        if (!$application->canBeRevised()) {
            return response()->json([
                'message' => 'Эта заявка не может быть отредактирована'
            ], 403);
        }

        // Обновляем документы
        $documents = $application->documents ?? [];
        $dir = "application_docs/{$application->registration_number}";
        $map = [
            'student_photo' => 'student_photo',
            'guardian_application' => 'guardian_application',
            'birth_certificate' => 'birth_certificate',
            'student_pin_doc' => 'student_pin_doc',
            'guardian_passport' => 'guardian_passport',
            'medical_certificate' => 'medical_certificate',
            'previous_school_record' => 'previous_school_record',
        ];

        foreach ($map as $input => $key) {
            if ($request->hasFile($input)) {
                // Удаляем старый файл, если есть
                if (!empty($documents[$key])) {
                    Storage::disk('local')->delete($documents[$key]);
                }
                // Сохраняем новый файл
                $path = $request->file($input)->store($dir, 'local');
                $documents[$key] = $path;
            }
        }

        // Обновляем заявку
        $application->update([
            'documents' => $documents,
            'status' => 'pending',
            'revision_token' => null, // сбрасываем токен
            'admin_comment' => null,
            'submitted_at' => now(),
        ]);

        // Добавляем запись в историю
        $application->reviews()->create([
            'reviewer_id' => $application->reviewed_by ?? 1, // если нет reviewer_id, ставим системного пользователя
            'action' => 'resubmitted',
            'comment' => 'Заявка отредактирована и отправлена повторно',
        ]);

        return response()->json([
            'message' => 'Заявка успешно обновлена и отправлена на модерацию',
            'registration_number' => $application->registration_number,
        ]);
    }
}
