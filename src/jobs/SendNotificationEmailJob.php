<?php
namespace amici\SuperMailer\jobs;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\Plugin;
use Craft;
use craft\queue\BaseJob;
use Throwable;

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

        try {
            Plugin::getInstance()->getMailer()->sendNotification($notification, $this->eventContext);
        } catch (Throwable $e) {
            Craft::error(
                Craft::t('super-mailer', 'Super Mailer failed to send notification {id}: {message}', [
                    'id' => $this->notificationId,
                    'message' => $e->getMessage(),
                ]),
                __METHOD__
            );
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('super-mailer', 'Sending Super Mailer notification');
    }
}
