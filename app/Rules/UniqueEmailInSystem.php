<?php

namespace App\Rules;

use App\Models\StudentApplication;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueEmailInSystem implements ValidationRule
{
    protected bool $allowSameInApplication;

    /**
     * Создать новый экземпляр правила
     *
     * @param bool $allowSameInApplication Разрешить ли совпадение email студента и опекуна в одной заявке
     */
    public function __construct(bool $allowSameInApplication = true)
    {
        $this->allowSameInApplication = $allowSameInApplication;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Проверка на пустое значение
        if (empty($value)) {
            return;
        }

        // 1. Проверка в таблице users
        if (User::where('email', $value)->exists()) {
            $fail('Этот email уже зарегистрирован в системе.');
            return;
        }

        // 2. Проверка в активных заявках (pending, needs_revision)
        // Ищем заявки где этот email используется
        $existsInApplications = StudentApplication::whereIn('status', ['pending', 'needs_revision'])
            ->where(function($query) use ($value) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(application_data, '$.student.email')) = ?", [$value])
                      ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(application_data, '$.guardian.email')) = ?", [$value]);
            })
            ->exists();

        if ($existsInApplications) {
            $fail('Заявка с этим email уже находится на рассмотрении.');
            return;
        }

        // Примечание: мы НЕ проверяем совпадение email студента и опекуна в текущей заявке,
        // так как это допустимо (один email на семью)
    }
}
