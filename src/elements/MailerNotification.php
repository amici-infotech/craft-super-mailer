<?php
namespace amici\SuperMailer\elements;

use amici\SuperMailer\elements\db\MailerNotificationQuery;
use amici\SuperMailer\Plugin;
use amici\SuperMailer\records\MailerNotificationRecord;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Edit;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use Exception;

class MailerNotification extends Element
{
    public ?string $handle = null;
    public ?string $eventClass = null;
    public ?string $eventConstant = null;
    public ?string $eventName = null;
    public ?string $toEmails = null;
    public ?string $ccEmails = null;
    public ?string $bccEmails = null;
    public ?string $fromEmail = null;
    public ?string $fromName = null;
    public ?string $replyTo = null;
    public ?string $emailSubject = null;
    public ?string $htmlTemplatePath = null;
    public ?string $plainTextTemplatePath = null;
    public bool $enabledNotification = true;

    public static function displayName(): string
    {
        return Craft::t('super-mailer', 'Notification');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('super-mailer', 'notification');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('super-mailer', 'Notifications');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('super-mailer', 'notifications');
    }

    public static function refHandle(): ?string
    {
        return 'mailer-notification';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): MailerNotificationQuery
    {
        return new MailerNotificationQuery(static::class);
    }

    public function getStatus(): ?string
    {
        return $this->enabledNotification ? self::STATUS_ENABLED : self::STATUS_DISABLED;
    }

    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        return null;
    }

    protected static function defineSources(string $context = 'index'): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('super-mailer', 'All Notifications'),
            ],
            [
                'key' => 'enabled',
                'label' => Craft::t('super-mailer', 'Enabled'),
                'criteria' => ['enabledNotification' => true],
            ],
            [
                'key' => 'disabled',
                'label' => Craft::t('super-mailer', 'Disabled'),
                'criteria' => ['enabledNotification' => false],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('super-mailer', 'Handle')],
            'eventLabel' => ['label' => Craft::t('super-mailer', 'Event')],
            'toEmails' => ['label' => Craft::t('super-mailer', 'Send To')],
            'emailSubject' => ['label' => Craft::t('super-mailer', 'Subject')],
            'preview' => ['label' => Craft::t('super-mailer', 'Preview')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['eventLabel', 'toEmails', 'emailSubject', 'preview'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('super-mailer', 'Title'),
                'orderBy' => 'content.title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('super-mailer', 'Handle'),
                'orderBy' => 'super_mailer_notifications.handle',
                'attribute' => 'handle',
            ],
            [
                'label' => Craft::t('super-mailer', 'Event'),
                'orderBy' => 'super_mailer_notifications.eventName',
                'attribute' => 'eventName',
            ],
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'elements.dateUpdated',
                'attribute' => 'dateUpdated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            [
                'type' => Edit::class,
                'label' => Craft::t('super-mailer', 'Edit notification'),
            ],
            Delete::class,
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'eventClass', 'eventConstant', 'eventName', 'toEmails', 'emailSubject'];
    }

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('super-mailer/notifications/' . $this->id) : null;
    }

    protected function cpEditUrl(): ?string
    {
        return $this->getCpEditUrl();
    }

    public function getPreviewUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('super-mailer/notifications/' . $this->id . '/preview') : null;
    }

    public function getEventLabel(): string
    {
        if (!$this->eventClass || !$this->eventName) {
            return Craft::t('super-mailer', 'No event selected');
        }

        return $this->eventClass . '::' . ($this->eventConstant ?: $this->eventName);
    }

    public function canView(User $user): bool
    {
        return $user->can('super-mailer:view-notifications') || $user->can('super-mailer:manage-notifications');
    }

    public function canSave(User $user): bool
    {
        return $user->can('super-mailer:manage-notifications');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('super-mailer:manage-notifications');
    }

    protected function tableAttributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'eventLabel' => Html::tag('code', Html::encode($this->getEventLabel()), ['style' => 'font-size:11px;']),
            'preview' => $this->getPreviewUrl()
                ? Html::a(Craft::t('super-mailer', 'Preview'), $this->getPreviewUrl(), ['target' => '_blank'])
                : '',
            'toEmails' => Html::encode($this->shortenList((string)$this->toEmails)),
            'emailSubject' => Html::encode((string)$this->emailSubject),
            default => parent::tableAttributeHtml($attribute),
        };
    }

    public function beforeValidate(): bool
    {
        $this->handle = trim((string)$this->handle);
        if ($this->handle === '' && $this->title) {
            $this->handle = $this->generateHandle((string)$this->title);
        }

        return parent::beforeValidate();
    }

    public function beforeSave(bool $isNew): bool
    {
        $this->title = trim((string)$this->title);
        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new MailerNotificationRecord();
            $record->id = $this->id;
        } else {
            $record = MailerNotificationRecord::findOne($this->id);
            if (!$record) {
                throw new Exception('Invalid notification ID: ' . $this->id);
            }
        }

        $record->handle = (string)$this->handle;
        $record->eventClass = (string)$this->eventClass;
        $record->eventConstant = (string)$this->eventConstant;
        $record->eventName = (string)$this->eventName;
        $record->toEmails = (string)$this->toEmails;
        $record->ccEmails = $this->blankToNull($this->ccEmails);
        $record->bccEmails = $this->blankToNull($this->bccEmails);
        $record->fromEmail = $this->blankToNull($this->fromEmail);
        $record->fromName = $this->blankToNull($this->fromName);
        $record->replyTo = $this->blankToNull($this->replyTo);
        $record->emailSubject = (string)$this->emailSubject;
        $record->htmlTemplatePath = $this->blankToNull($this->htmlTemplatePath);
        $record->plainTextTemplatePath = $this->blankToNull($this->plainTextTemplatePath);
        $record->enabled = $this->enabledNotification;
        $record->save(false);

        parent::afterSave($isNew);
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['title', 'handle', 'eventClass', 'eventConstant', 'eventName', 'toEmails', 'emailSubject'], 'required'];
        $rules[] = [['handle', 'eventClass', 'eventConstant', 'eventName', 'fromEmail', 'fromName', 'replyTo', 'emailSubject', 'htmlTemplatePath', 'plainTextTemplatePath'], 'string', 'max' => 255];
        $rules[] = [['toEmails', 'ccEmails', 'bccEmails'], 'string'];
        $rules[] = [['enabledNotification'], 'boolean'];
        $rules[] = [['replyTo'], 'email'];
        $rules[] = [['toEmails', 'ccEmails', 'bccEmails'], 'validateEmailList'];
        $rules[] = [['handle'], 'validateUniqueHandle'];
        $rules[] = [['eventName'], 'validateSelectedEvent'];
        $rules[] = [['htmlTemplatePath'], 'validateTemplatePathRequired', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateEmailList(string $attribute): void
    {
        $value = (string)$this->{$attribute};
        if (trim($value) === '') {
            return;
        }

        if (str_contains($value, '{{') || str_contains($value, '{%')) {
            return;
        }

        $validator = new \yii\validators\EmailValidator();
        foreach (Plugin::getInstance()->getMailer()->parseEmailList($value) as $email) {
            if (!$validator->validate($email)) {
                $this->addError($attribute, Craft::t('super-mailer', '"{email}" is not a valid email address.', ['email' => $email]));
            }
        }
    }

    public function validateUniqueHandle(): void
    {
        if (!$this->handle) {
            return;
        }

        $query = self::find()->handle($this->handle);
        if ($this->id) {
            $query->id('not ' . $this->id);
        }

        if ($query->exists()) {
            $this->addError('handle', Craft::t('super-mailer', 'Handle "{handle}" is already in use.', ['handle' => $this->handle]));
        }
    }

    public function validateSelectedEvent(): void
    {
        if (!$this->eventClass || !$this->eventName) {
            return;
        }

        if (!Plugin::getInstance()->getEvents()->isValidEvent((string)$this->eventClass, (string)$this->eventName)) {
            $this->addError('eventName', Craft::t('super-mailer', 'The selected event is no longer available.'));
        }
    }

    public function validateTemplatePathRequired(): void
    {
        if (trim((string)$this->htmlTemplatePath) !== '' || trim((string)$this->plainTextTemplatePath) !== '') {
            return;
        }

        $message = Craft::t('super-mailer', 'Enter an HTML email template path or a plain text email template path.');
        $this->addError('htmlTemplatePath', $message);
        $this->addError('plainTextTemplatePath', $message);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function generateHandle(string $title): string
    {
        $handle = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]+/', ' ', $title))));
        return $handle !== '' ? $handle : 'notification';
    }

    private function shortenList(string $value): string
    {
        $emails = Plugin::getInstance()->getMailer()->parseEmailList($value);
        $label = implode(', ', array_slice($emails, 0, 2));
        if (count($emails) > 2) {
            $label .= ', +' . (count($emails) - 2);
        }

        return $label;
    }
}
