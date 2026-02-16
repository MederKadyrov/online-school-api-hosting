<?php

namespace App\Rules;

use App\Models\StudentApplication;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniquePinInSystem implements ValidationRule
{
    protected ?string $otherPin;

    /**
     * Создать новый экземпляр правила
     *
     * @param string|null $otherPin PIN другого человека в текущей заявке (для проверки совпадения)
     */
    public function __construct(?string $otherPin = null)
    {
        $this->otherPin = $otherPin;
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
        if (User::where('pin', $value)->exists()) {
            $fail('Этот ПИН уже зарегистрирован в системе.');
            return;
        }

        // 2. Проверка в активных заявках (pending, needs_revision)
        $existsInApplications = StudentApplication::whereIn('status', ['pending', 'needs_revision'])
            ->where(function($query) use ($value) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(application_data, '$.student.pin')) = ?", [$value])
                      ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(application_data, '$.guardian.pin')) = ?", [$value]);
            })
            ->exists();

        if ($existsInApplications) {
            $fail('Заявка с этим ПИН уже находится на рассмотрении.');
            return;
        }

        // 3. Проверка на совпадение с другим PIN в текущей заявке
        if ($this->otherPin !== null && $value === $this->otherPin) {
            $fail('ПИН студента и опекуна не могут совпадать.');
            return;
        }
    }
}
