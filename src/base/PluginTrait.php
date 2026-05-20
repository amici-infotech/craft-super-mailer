<?php
namespace amici\SuperMailer\base;

use amici\SuperMailer\services\EmailLogService;
use amici\SuperMailer\services\EventRegistryService;
use amici\SuperMailer\services\MailerService;
use amici\SuperMailer\services\NotificationService;

trait PluginTrait
{
    private function _setPluginComponents(): void
    {
        $this->setComponents([
            'events' => EventRegistryService::class,
            'logs' => EmailLogService::class,
            'mailer' => MailerService::class,
            'notifications' => NotificationService::class,
        ]);
    }

    public function getEvents(): EventRegistryService
    {
        return $this->get('events');
    }

    public function getMailer(): MailerService
    {
        return $this->get('mailer');
    }

    public function getLogs(): EmailLogService
    {
        return $this->get('logs');
    }

    public function getNotifications(): NotificationService
    {
        return $this->get('notifications');
    }
}
