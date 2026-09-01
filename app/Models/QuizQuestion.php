<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class QuizQuestion extends Model
{
    protected $fillable = [
        'quiz_id',
        'type',     // 'single' | 'multiple' | 'text'
        'text',
        'image_path',
        'points',
        'position', // для сортировки
    ];

    protected $casts = [
        'points'   => 'integer',
        'position' => 'integer',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Родительский тест */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /** Варианты ответа (для single/multiple) */
    public function options()
    {
        // было: return $this->hasMany(QuizOption::class)->orderBy('position')->orderBy('id');
        return $this->hasMany(QuizOption::class, 'question_id') // <-- ЯВНО УКАЗАЛИ FK
        ->orderBy('position')
            ->orderBy('id');
    }

    /** Ответы студентов на этот вопрос (если нужна связь) */
    public function answers()
    {
        return $this->hasMany(QuizAnswer::class, 'question_id');
    }
}
