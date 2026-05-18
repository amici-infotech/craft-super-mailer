<?php
namespace amici\SuperMailer\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property string $eventClass
 * @property string $eventConstant
 * @property string $eventName
 * @property string $toEmails
 * @property string|null $ccEmails
 * @property string|null $bccEmails
 * @property string|null $fromEmail
 * @property string|null $fromName
 * @property string|null $replyTo
 * @property string $emailSubject
 * @property string|null $htmlTemplatePath
 * @property string|null $plainTextTemplatePath
 * @property bool $enabled
 */
class MailerNotificationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%super_mailer_notifications}}';
    }

    public function rules(): array
    {
        return [
            [['handle', 'eventClass', 'eventConstant', 'eventName', 'toEmails', 'emailSubject'], 'required'],
            [['toEmails', 'ccEmails', 'bccEmails'], 'string'],
            [['enabled'], 'boolean'],
            [['handle', 'eventClass', 'eventConstant', 'eventName', 'fromEmail', 'fromName', 'replyTo', 'emailSubject', 'htmlTemplatePath', 'plainTextTemplatePath'], 'string', 'max' => 255],
        ];
    }
}
