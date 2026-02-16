<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use App\Rules\UniquePinInSystem;
use App\Rules\UniqueEmailInSystem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;


class StudentRegisterWizardController extends Controller
{
    /** Общие правила для Шага 1 (без файлов) */
    protected function rulesPersonal(): array
    {
        return [
            'guardian_type' => ['required', Rule::in(['parent','representative'])],

            'guardian' => ['required','array'],
            'guardian.last_name'   => ['required','string','max:60'],
            'guardian.first_name'  => ['required','string','max:60'],
            'guardian.middle_name' => ['nullable','string','max:60'],
            'guardian.sex'         => ['required', Rule::in(['male','female'])],
            'guardian.citizenship' => ['required','string','max:10'],
            'guardian.pin'         => ['required','regex:/^\d{14}$/'],
            'guardian.phone'       => ['required','string','max:30'],
            'guardian.address'     => ['required','string','max:255'],
            'guardian.email'       => ['nullable','email','max:255'],

            'student' => ['required','array'],
            'student.last_name'    => ['required','string','max:60'],
            'student.first_name'   => ['required','string','max:60'],
            'student.middle_name'  => ['nullable','string','max:60'],
            'student.sex'          => ['required', Rule::in(['male','female'])],
            'student.citizenship'  => ['required','string','max:10'],
            'student.birth_date'   => ['required','date'],
            'student.level_id'     => ['required','exists:levels,id'], // уровень из справочника
            'student.pin'          => ['required','regex:/^\d{14}$/'],
            'student.phone'        => ['nullable','string','max:30'],
            'student.email'        => ['nullable','email','max:255'],
            'student.password'     => ['required','string','min:8','confirmed'],
            'student.class_letter' => ['nullable','string','max:2'],
        ];
    }

    /** Префлайт-валидация (Шаг 1): проверяем данные, НИЧЕГО НЕ СОЗДАЁМ */
    public function validateOnly(Request $r)
    {
        // Получаем данные для проверки ПИНов друг с другом
        $guardianPin = $r->input('guardian.pin');
        $studentPin = $r->input('student.pin');

        $rules = $this->rulesPersonal();

        // Применяем кастомные правила для уникальности
        $rules['guardian.pin'] = [
            'required',
            'regex:/^\d{14}$/',
            new UniquePinInSystem($studentPin), // передаем PIN студента для проверки совпадения
        ];

        $rules['guardian.email'] = [
            'nullable',
            'email',
            'max:255',
            new UniqueEmailInSystem(),
        ];

        $rules['student.pin'] = [
            'required',
            'regex:/^\d{14}$/',
            new UniquePinInSystem($guardianPin), // передаем PIN опекуна для проверки совпадения
        ];

        $rules['student.email'] = [
            'nullable',
            'email',
            'max:255',
            new UniqueEmailInSystem(),
        ];

        $r->validate($rules);
        return response()->noContent(); // 204
    }

    /** Итоговое создание заявки + документы (Шаг 2): всё в одной транзакции */
    public function createWithDocuments(Request $r)
    {
        // Получаем данные для проверки ПИНов друг с другом
        $guardianPin = $r->input('guardian.pin');
        $studentPin = $r->input('student.pin');

        $rules = $this->rulesPersonal();

        // Применяем кастомные правила для уникальности
        $rules['guardian.pin'] = [
            'required',
            'regex:/^\d{14}$/',
            new UniquePinInSystem($studentPin),
        ];

        $rules['guardian.email'] = [
            'nullable',
            'email',
            'max:255',
            new UniqueEmailInSystem(),
        ];

        $rules['student.pin'] = [
            'required',
            'regex:/^\d{14}$/',
            new UniquePinInSystem($guardianPin),
        ];

        $rules['student.email'] = [
            'nullable',
            'email',
            'max:255',
            new UniqueEmailInSystem(),
        ];

        // Добавляем правила для файлов
        $rules += [
            'student_photo'           => ['nullable','file','mimes:jpeg,jpg,png','max:4096'],
            'guardian_application'    => ['required','file','mimes:jpeg,jpg,png,pdf','max:8192'],
            'birth_certificate'       => ['required','file','mimes:jpeg,jpg,png,pdf','max:8192'],
            'student_pin_doc'         => ['required','file','mimes:jpeg,jpg,png,pdf','max:8192'],
            'guardian_passport'       => ['required','file','mimes:jpeg,jpg,png,pdf','max:8192'],
            'medical_certificate'     => ['nullable','file','mimes:jpeg,jpg,png,pdf','max:8192'],
            'previous_school_record'  => ['required','file','mimes:jpeg,jpg,png,pdf','max:8192'],
        ];

        // Валидируем и данные, и файлы
        $data = $r->validate($rules);

        // Кастомные правила валидации уже проверили уникальность PIN и Email,
        // поэтому дополнительная проверка не требуется

        $application = DB::transaction(function () use ($data, $r) {
            // Генерируем уникальный регистрационный номер
            $registrationNumber = StudentApplication::generateUniqueRegistrationNumber();

            // Сохраняем документы
            $documents = [];
            $dir = "application_docs/{$registrationNumber}";
            $map = [
                'student_photo'          => 'student_photo',
                'guardian_application'   => 'guardian_application',
                'birth_certificate'      => 'birth_certificate',
                'student_pin_doc'        => 'student_pin_doc',
                'guardian_passport'      => 'guardian_passport',
                'medical_certificate'    => 'medical_certificate',
                'previous_school_record' => 'previous_school_record',
            ];

            foreach ($map as $input => $key) {
                if ($r->hasFile($input)) {
                    $path = $r->file($input)->store($dir, 'local');
                    $documents[$key] = $path;
                }
            }

            // Создаём заявку
            $application = StudentApplication::create([
                'registration_number' => $registrationNumber,
                'guardian_email'   => $data['guardian']['email'] ?? '',
                'student_email'    => $data['student']['email'] ?? '',
                'application_data' => [
                    'guardian_type' => $data['guardian_type'],
                    'guardian'      => $data['guardian'],
                    'student'       => $data['student'],
                ],
                'documents'        => $documents,
                'status'           => 'pending',
                'submitted_at'     => now(),
            ]);

            return $application;
        });

        return response()->json([
            'registration_number' => $application->registration_number,
            'message' => 'Заявка успешно отправлена. Сохраните ваш регистрационный номер для проверки статуса.'
        ], 201);
    }
}
