<?php
namespace amici\SuperMailer\jobs;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\Plugin;
use Craft;
use craft\queue\BaseJob;

class SendNotificationEmailJob extends BaseJob
{
    public int $notificationId;
    public array $eventContext = [];

    public function execute($queue): void
    {
        $notification = MailerNotification::find()
            ->id($this->notificationId)
            ->status(null)
            ->one();

        if (!$notification instanceof MailerNotification || !$notification->enabledNotification) {
            return;
        }

        Plugin::getInstance()->getMailer()->sendNotification($notification, $this->eventContext);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('super-mailer', 'Sending Super Mailer notification');
    }
}
