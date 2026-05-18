<?php
namespace amici\SuperMailer\base;

use amici\SuperMailer\services\EventRegistryService;
use amici\SuperMailer\services\MailerService;
use amici\SuperMailer\services\NotificationService;

trait PluginTrait
{
    private function _setPluginComponents(): void
    {
        $this->setComponents([
            'events' => EventRegistryService::class,
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

    public function getNotifications(): NotificationService
    {
        return $this->get('notifications');
    }
}
