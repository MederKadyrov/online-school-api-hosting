<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'reviewer_id',
        'action',
        'comment',
    ];

    /**
     * Связь с заявкой
     */
    public function application()
    {
        return $this->belongsTo(StudentApplication::class, 'application_id');
    }

    /**
     * Связь с проверяющим админом
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
