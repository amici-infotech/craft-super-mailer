<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\jobs\SendNotificationEmailJob;
use amici\SuperMailer\Plugin;
use amici\SuperMailer\records\EmailLogRecord;
use Craft;
use craft\db\Query;
use DateTimeImmutable;
use Throwable;
use yii\base\Component;

/**
 * Persists, deletes, purges, counts, and queues resends for Super Mailer email log records.
 */
class EmailLogService extends Component
{
    /**
     * Stores a delivery attempt with notification metadata, message metadata, and serialized event context.
     *
     * The service guards against missing tables so send attempts do not crash during installs, migrations,
     * or disabled-plugin states where the log table is not yet available.
     *
     * @param MailerNotification $notification Notification definition that produced the attempt.
     * @param array $eventContext Serialized event context used to render and resend the message.
     * @param string $status Log status such as success or failed.
     * @param string|null $error Full failure details when delivery failed.
     * @param array $messageData Rendered recipients, subject, body, and sender details.
     */
    public function record(
        MailerNotification $notification,
        array $eventContext,
        string $status,
        ?string $error,
        array $messageData
    ): void {
        if (!$this->tableExists()) {
            return;
        }

        $element = is_array($eventContext['element'] ?? null) ? $eventContext['element'] : [];
        $record = new EmailLogRecord();
        $record->notificationId = $notification->id;
        $record->notificationTitle = (string)$notification->title;
        $record->status = $status;
        $record->error = $error;
        $record->subject = $messageData['subject'] ?? null;
        $record->toEmails = $this->encode($messageData['to'] ?? []);
        $record->ccEmails = $this->encode($messageData['cc'] ?? []);
        $record->bccEmails = $this->encode($messageData['bcc'] ?? []);
        $record->fromEmail = $this->encode($messageData['from'] ?? []);
        $record->replyTo = $this->encode($messageData['replyTo'] ?? []);
        $record->htmlTemplatePath = $notification->htmlTemplatePath;
        $record->plainTextTemplatePath = $notification->plainTextTemplatePath;
        $record->eventClass = $eventContext['eventClass'] ?? null;
        $record->eventName = $eventContext['eventName'] ?? null;
        $record->senderClass = $eventContext['senderClass'] ?? null;
        $record->elementType = $element['type'] ?? null;
        $record->elementId = isset($element['id']) ? (int)$element['id'] : null;
        $record->siteId = isset($element['siteId']) ? (int)$element['siteId'] : null;
        $record->eventContext = $this->encode($eventContext);

        if (!$record->save()) {
            Craft::warning('Super Mailer could not save email log: ' . json_encode($record->getErrors()), __METHOD__);
        }
    }

    /**
     * Deletes logs older than the configured or supplied retention period.
     *
     * @param int $retentionDays retentionDays value used by this method.
     * @return int Return value produced by this method.
     */
    public function purgeByRetention(?int $retentionDays = null): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $retentionDays ??= Plugin::getInstance()->getSettings()->emailLogRetentionDays;
        $retentionDays = (int)$retentionDays;
        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff = new DateTimeImmutable(sprintf('-%d days', $retentionDays));

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(EmailLogRecord::tableName(), ['<', 'dateCreated', $cutoff->format('Y-m-d H:i:s')])
            ->execute();
    }

    /**
     * Deletes every stored email log row.
     *
     * @return int Return value produced by this method.
     */
    public function purgeAll(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(EmailLogRecord::tableName())
            ->execute();
    }

    /**
     * Deletes selected email logs by ID.
     *
     * @param array $ids ids value used by this method.
     * @return int Return value produced by this method.
     */
    public function deleteByIds(array $ids): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(EmailLogRecord::tableName(), ['id' => $ids])
            ->execute();
    }

    /**
     * Queues resend jobs for selected logs using their stored event contexts.
     *
     * @param array $ids ids value used by this method.
     * @return array Return value produced by this method.
     */
    public function resendByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->tableExists()) {
            return ['queued' => 0, 'skipped' => 0];
        }

        $logs = EmailLogRecord::find()
            ->where(['id' => $ids])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $queued = 0;
        $skipped = 0;

        foreach ($logs as $log) {
            if (!$log instanceof EmailLogRecord || !$log->notificationId || !$log->eventContext) {
                $skipped++;
                continue;
            }

            $notification = MailerNotification::find()
                ->id((int)$log->notificationId)
                ->status(null)
                ->one();

            if (!$notification instanceof MailerNotification || !$notification->enabledNotification) {
                $skipped++;
                continue;
            }

            try {
                $context = json_decode($log->eventContext, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $skipped++;
                continue;
            }

            if (!is_array($context)) {
                $skipped++;
                continue;
            }

            Craft::$app->getQueue()->push(new SendNotificationEmailJob([
                'notificationId' => (int)$notification->id,
                'eventContext' => $context,
            ]));
            $queued++;
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    /**
     * Counts stored email log rows.
     *
     * @return int Return value produced by this method.
     */
    public function count(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int)(new Query())
            ->from(EmailLogRecord::tableName())
            ->count();
    }

    /**
     * JSON-encodes log values for storage, returning null when encoding fails.
     *
     * @param mixed $value value value used by this method.
     * @return ?string Return value produced by this method.
     */
    private function encode(mixed $value): ?string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Checks whether the email log table exists before touching it.
     *
     * @return bool Return value produced by this method.
     */
    private function tableExists(): bool
    {
        try {
            return Craft::$app->getDb()->getSchema()->getTableSchema(EmailLogRecord::tableName(), true) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
