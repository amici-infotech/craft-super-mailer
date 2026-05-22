<?php
namespace amici\SuperMailer\jobs;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Craft queue job that sends a queued Super Mailer notification using serialized event context.
 */
class SendNotificationEmailJob extends BaseJob
{
    public int $notificationId;
    public array $eventContext = [];

    /**
     * Runs the queued notification send job.
     *
     * @param mixed $queue queue value used by this method.
     * @return void Return value produced by this method.
     */
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

    /**
     * Returns the queue job description shown in Craft queue utilities.
     *
     * @return ?string Return value produced by this method.
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('super-mailer', 'Sending Super Mailer notification');
    }
}
