<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\Plugin;
use amici\SuperMailer\records\EmailLogRecord;
use Craft;
use craft\db\Query;
use DateTimeImmutable;
use Throwable;
use yii\base\Component;

class EmailLogService extends Component
{
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

    public function purgeAll(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(EmailLogRecord::tableName())
            ->execute();
    }

    public function count(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int)(new Query())
            ->from(EmailLogRecord::tableName())
            ->count();
    }

    private function encode(mixed $value): ?string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(): bool
    {
        try {
            return Craft::$app->getDb()->getSchema()->getTableSchema(EmailLogRecord::tableName(), true) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
