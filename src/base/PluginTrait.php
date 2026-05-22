<?php
namespace amici\SuperMailer\base;

use amici\SuperMailer\services\EmailLogService;
use amici\SuperMailer\services\EventRegistryService;
use amici\SuperMailer\services\MailerService;
use amici\SuperMailer\services\NotificationService;

/**
 * Shared plugin service registration trait that wires Super Mailer service components and typed service accessors.
 */
trait PluginTrait
{
    /**
     * Registers service components used by the plugin trait accessors.
     *
     * @return void Return value produced by this method.
     */
    private function _setPluginComponents(): void
    {
        $this->setComponents([
            'events' => EventRegistryService::class,
            'logs' => EmailLogService::class,
            'mailer' => MailerService::class,
            'notifications' => NotificationService::class,
        ]);
    }

    /**
     * Builds and caches the complete list of supported events available to notifications.
     *
     * @return EventRegistryService Return value produced by this method.
     */
    public function getEvents(): EventRegistryService
    {
        return $this->get('events');
    }

    /**
     * Returns the mailer service component.
     *
     * @return MailerService Return value produced by this method.
     */
    public function getMailer(): MailerService
    {
        return $this->get('mailer');
    }

    /**
     * Returns the email log service component.
     *
     * @return EmailLogService Return value produced by this method.
     */
    public function getLogs(): EmailLogService
    {
        return $this->get('logs');
    }

    /**
     * Returns the notification listener and condition service component.
     *
     * @return NotificationService Return value produced by this method.
     */
    public function getNotifications(): NotificationService
    {
        return $this->get('notifications');
    }
}
