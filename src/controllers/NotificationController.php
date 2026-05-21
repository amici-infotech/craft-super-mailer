<?php
namespace amici\SuperMailer\controllers;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\Plugin;
use amici\SuperMailer\records\EmailLogRecord;
use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class NotificationController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-mailer:view-notifications');
        return $this->renderTemplate('super-mailer/notifications/index');
    }

    public function actionEdit(?int $notificationId = null, ?MailerNotification $notification = null): Response
    {
        $this->requirePermission('super-mailer:manage-notifications');

        if ($notification === null && $notificationId !== null) {
            $notification = MailerNotification::find()
                ->id($notificationId)
                ->status(null)
                ->one();

            if (!$notification instanceof MailerNotification) {
                throw new NotFoundHttpException('Notification not found');
            }
        }

        if ($notification === null) {
            $notification = new MailerNotification();
            $notification->enabledNotification = true;
        }

        $eventValue = $notification->eventClass && $notification->eventName
            ? Plugin::getInstance()->getEvents()->encodeEventValue(
                (string)$notification->eventClass,
                (string)$notification->eventName,
                (string)$notification->eventConstant
            )
            : '';

        return $this->renderTemplate('super-mailer/notifications/_edit', [
            'notification' => $notification,
            'isNew' => !$notification->id,
            'eventOptions' => Plugin::getInstance()->getEvents()->getSelectOptions(),
            'eventValue' => $eventValue,
            'conditionFieldOptions' => $this->conditionFieldOptions(),
            'conditionSuggestions' => $this->conditionSuggestions(),
            'conditionAuthorOptions' => $this->conditionAuthorOptions($notification),
            'recentLogs' => $notification->id ? $this->recentLogs((int)$notification->id) : [],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $request = Craft::$app->getRequest();
        $notificationId = $request->getBodyParam('id') ?? $request->getBodyParam('notificationId');

        if ($notificationId) {
            $notification = MailerNotification::find()
                ->id($notificationId)
                ->status(null)
                ->one();

            if (!$notification instanceof MailerNotification) {
                throw new NotFoundHttpException('Notification not found');
            }
        } else {
            $notification = new MailerNotification();
        }

        $event = Plugin::getInstance()->getEvents()->getEventByValue($request->getBodyParam('eventValue'));

        $notification->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $notification->title = $request->getBodyParam('title');
        $notification->handle = $request->getBodyParam('handle');
        $notification->eventClass = $event['class'] ?? $request->getBodyParam('eventClass');
        $notification->eventConstant = $event['constant'] ?? $request->getBodyParam('eventConstant');
        $notification->eventName = $event['eventName'] ?? $request->getBodyParam('eventName');
        $notification->toEmails = $request->getBodyParam('toEmails');
        $notification->ccEmails = $request->getBodyParam('ccEmails');
        $notification->bccEmails = $request->getBodyParam('bccEmails');
        $notification->fromEmail = $request->getBodyParam('fromEmail');
        $notification->fromName = $request->getBodyParam('fromName');
        $notification->replyTo = $request->getBodyParam('replyTo');
        $notification->emailSubject = $request->getBodyParam('emailSubject');
        $notification->htmlTemplatePath = $request->getBodyParam('htmlTemplatePath');
        $notification->plainTextTemplatePath = $request->getBodyParam('plainTextTemplatePath');
        $notification->conditionMatchMode = $request->getBodyParam('conditionMatchMode', 'all');
        $notification->conditionRules = $request->getBodyParam('conditionRules', []);
        $notification->phpCondition = $request->getBodyParam('phpCondition');
        $notification->enabledNotification = (bool)$request->getBodyParam('enabledNotification', false);
        $notification->setScenario(Element::SCENARIO_LIVE);

        if (!Craft::$app->getElements()->saveElement($notification)) {
            Craft::$app->getUrlManager()->setRouteParams([
                'notification' => $notification,
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('super-mailer', 'Notification saved.'));

        return $this->redirectToPostedUrl($notification);
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $notificationId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $notification = MailerNotification::find()
            ->id($notificationId)
            ->status(null)
            ->one();

        if (!$notification instanceof MailerNotification) {
            throw new NotFoundHttpException('Notification not found');
        }

        if (!Craft::$app->getElements()->deleteElement($notification)) {
            return $this->asModelFailure(
                $notification,
                Craft::t('super-mailer', 'Could not delete notification.'),
                'notification'
            );
        }

        return $this->asModelSuccess(
            $notification,
            Craft::t('super-mailer', 'Notification deleted.'),
            'notification',
            ['success' => true]
        );
    }

    public function actionPreview(int $notificationId): Response
    {
        $this->requirePermission('super-mailer:view-notifications');

        $notification = MailerNotification::find()
            ->id($notificationId)
            ->status(null)
            ->one();

        if (!$notification instanceof MailerNotification) {
            throw new NotFoundHttpException('Notification not found');
        }

        $elementId = Craft::$app->getRequest()->getQueryParam('id');
        $elementId = $elementId !== null && $elementId !== '' ? (int)$elementId : null;

        return $this->renderTemplate('super-mailer/notifications/preview', [
            'notification' => $notification,
            'preview' => Plugin::getInstance()->getMailer()->preview($notification, $elementId),
        ]);
    }

    public function actionTestSend(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $notificationId = (int)Craft::$app->getRequest()->getRequiredBodyParam('notificationId');
        $notification = MailerNotification::find()
            ->id($notificationId)
            ->status(null)
            ->one();

        if (!$notification instanceof MailerNotification) {
            throw new NotFoundHttpException('Notification not found');
        }

        $email = trim((string)Craft::$app->getRequest()->getRequiredBodyParam('testEmail'));
        $validator = new \yii\validators\EmailValidator();
        if (!$validator->validate($email)) {
            Craft::$app->getSession()->setError(Craft::t('super-mailer', 'Enter a valid test email address.'));
            return $this->redirectToPostedUrl();
        }

        $elementId = Craft::$app->getRequest()->getBodyParam('elementId');
        $elementId = $elementId !== null && $elementId !== '' ? (int)$elementId : null;
        $context = Plugin::getInstance()->getNotifications()->previewEventContext($notification, $elementId);

        if (Plugin::getInstance()->getMailer()->sendTestNotification($notification, $context, $email)) {
            Craft::$app->getSession()->setNotice(Craft::t('super-mailer', 'Test email sent to {email}.', [
                'email' => $email,
            ]));
        } else {
            Craft::$app->getSession()->setError(Craft::t('super-mailer', 'Could not send test email. Check the email logs for details.'));
        }

        return $this->redirectToPostedUrl();
    }

    private function conditionFieldOptions(): array
    {
        $options = [
            ['label' => Craft::t('super-mailer', 'Status'), 'value' => 'element.status'],
            ['label' => Craft::t('super-mailer', 'Is New'), 'value' => 'event.isNew'],
            ['label' => Craft::t('super-mailer', 'Site ID'), 'value' => 'element.siteId'],
            ['label' => Craft::t('super-mailer', 'Section Handle'), 'value' => 'entry.section.handle'],
            ['label' => Craft::t('super-mailer', 'Entry Type Handle'), 'value' => 'entry.type.handle'],
            ['label' => Craft::t('super-mailer', 'Author'), 'value' => 'entry.authorId'],
        ];

        return $options;
    }

    private function conditionSuggestions(): array
    {
        $sites = array_map(
            static fn($site): array => [
                'label' => $site->name . ' (' . $site->handle . ')',
                'value' => (string)$site->id,
            ],
            Craft::$app->getSites()->getAllSites()
        );

        $entries = Craft::$app->getEntries();
        $sections = [];
        if (method_exists($entries, 'getAllSections')) {
            $sections = array_map(
                static fn($section): array => [
                    'label' => $section->name,
                    'value' => $section->handle,
                ],
                $entries->getAllSections()
            );
        }

        $entryTypes = [];
        if (method_exists($entries, 'getAllEntryTypes')) {
            $entryTypes = array_map(
                static fn($entryType): array => [
                    'label' => $entryType->name,
                    'value' => $entryType->handle,
                ],
                $entries->getAllEntryTypes()
            );
        }

        return [
            'element.siteId' => $sites,
            'entry.section.handle' => $sections,
            'entry.type.handle' => $entryTypes,
        ];
    }

    private function conditionAuthorOptions(MailerNotification $notification): array
    {
        $ids = [];
        foreach ($notification->normalizedConditionRules() as $rule) {
            if (($rule['field'] ?? null) !== 'entry.authorId') {
                continue;
            }

            foreach (explode(',', (string)($rule['value'] ?? '')) as $id) {
                $id = (int)trim($id);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }

        $users = User::find()
            ->id($ids)
            ->status(null)
            ->all();
        $options = [];

        foreach ($users as $user) {
            if (!$user instanceof User || !$user->id) {
                continue;
            }

            $options[(string)$user->id] = [
                'label' => (string)$user,
                'html' => Cp::elementChipHtml($user, [
                    'showActionMenu' => false,
                    'showStatus' => true,
                ]),
            ];
        }

        return $options;
    }

    private function recentLogs(int $notificationId): array
    {
        $logs = (new Query())
            ->from(EmailLogRecord::tableName())
            ->where(['notificationId' => $notificationId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(10)
            ->all();

        foreach ($logs as &$log) {
            $log['toEmailsList'] = $this->decodeList($log['toEmails'] ?? null);
        }
        unset($log);

        return $logs;
    }

    private function decodeList(?string $value): array
    {
        if (!$value) {
            return [];
        }

        try {
            $decoded = Json::decode($value);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
