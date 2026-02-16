<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_applications', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number', 20)->unique();
            $table->string('guardian_email')->nullable();
            $table->string('student_email')->nullable(); // Без unique - уникальность проверяется через custom validation rules
            $table->json('application_data'); // хранит всю информацию о студенте и опекуне
            $table->json('documents')->nullable(); // массив путей к файлам
            $table->enum('status', ['pending', 'approved', 'rejected', 'needs_revision'])->default('pending');
            $table->string('revision_token')->nullable(); // токен для доступа к форме редактирования
            $table->text('admin_comment')->nullable(); // комментарий админа при отклонении или запросе изменений

            // Связи с созданными пользователями (заполняются при одобрении)
            $table->foreignId('guardian_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('student_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('student_id')->nullable()->constrained('students')->onDelete('set null');

            // Информация о модерации
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            // Индексы
            $table->index('status');
            $table->index('registration_number');
            $table->index(['guardian_email', 'student_email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_applications');
    }
};
