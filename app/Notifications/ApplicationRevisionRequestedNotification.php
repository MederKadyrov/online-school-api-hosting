<?php

namespace App\Notifications;

use App\Models\StudentApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationRevisionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $application;
    protected $revisionToken;

    public function __construct(StudentApplication $application, string $revisionToken)
    {
        $this->application = $application;
        $this->revisionToken = $revisionToken;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $studentData = $this->application->application_data['student'];
        $studentName = "{$studentData['last_name']} {$studentData['first_name']}";

        $revisionUrl = url("/revise-application?registration_number={$this->application->registration_number}&token={$this->revisionToken}");

        return (new MailMessage)
            ->subject('Требуется редактирование заявки')
            ->greeting('Здравствуйте!')
            ->line("Ваша заявка на зачисление студента {$studentName} требует редактирования.")
            ->line("Регистрационный номер заявки: {$this->application->registration_number}")
            ->line("Комментарий администратора: {$this->application->admin_comment}")
            ->line('Пожалуйста, внесите необходимые изменения и отправьте заявку повторно.')
            ->action('Редактировать заявку', $revisionUrl)
            ->line('Спасибо за ваше терпение!');
    }
}
