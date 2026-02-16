<?php

namespace App\Notifications;

use App\Models\StudentApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationRejectedNotification extends Notification implements ShouldQueue
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
            ->subject('Ваша заявка отклонена')
            ->greeting('Здравствуйте!')
            ->line("К сожалению, ваша заявка на зачисление студента {$studentName} была отклонена.")
            ->line("Регистрационный номер заявки: {$this->application->registration_number}")
            ->line("Причина: {$this->application->admin_comment}")
            ->line('Если у вас есть вопросы, пожалуйста, свяжитесь с администрацией школы.')
            ->line('Спасибо за ваш интерес к нашей школе.');
    }
}
