<?php

namespace App\Notifications;

use App\Models\StudentApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $application;

    public function __construct(StudentApplication $application)
    {
        $this->application = $application;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $studentData = $this->application->application_data['student'];
        $studentName = "{$studentData['last_name']} {$studentData['first_name']}";

        return (new MailMessage)
            ->subject('Ваша заявка одобрена')
            ->greeting('Здравствуйте!')
            ->line("Ваша заявка на зачисление студента {$studentName} была одобрена.")
            ->line("Регистрационный номер заявки: {$this->application->registration_number}")
            ->line('Теперь вы можете войти в систему, используя свои учетные данные.')
            ->action('Войти в систему', url('/login'))
            ->line('Спасибо за выбор нашей школы!');
    }
}
