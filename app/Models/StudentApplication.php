<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StudentApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'registration_number',
        'guardian_email',
        'student_email',
        'application_data',
        'documents',
        'status',
        'revision_token',
        'admin_comment',
        'guardian_user_id',
        'student_user_id',
        'student_id',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
    ];

    protected $casts = [
        'application_data' => 'array',
        'documents' => 'array',
        'reviewed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    /**
     * Генерирует уникальный регистрационный номер
     */
    public static function generateUniqueRegistrationNumber(): string
    {
        do {
            $number = 'REG' . strtoupper(Str::random(8));
        } while (self::where('registration_number', $number)->exists());

        return $number;
    }

    /**
     * Генерирует токен для редактирования заявки
     */
    public static function generateRevisionToken(): string
    {
        return Str::random(64);
    }

    /**
     * Связь с опекуном (User)
     */
    public function guardianUser()
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    /**
     * Связь со студентом (User)
     */
    public function studentUser()
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /**
     * Связь со студентом (Student)
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Связь с проверяющим админом
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * История проверок
     */
    public function reviews()
    {
        return $this->hasMany(ApplicationReview::class, 'application_id');
    }

    /**
     * Проверка, можно ли редактировать заявку
     */
    public function canBeRevised(): bool
    {
        return $this->status === 'needs_revision' && !empty($this->revision_token);
    }

    /**
     * Проверка, можно ли одобрить/отклонить заявку
     */
    public function canBeReviewed(): bool
    {
        return in_array($this->status, ['pending', 'needs_revision']);
    }
}
