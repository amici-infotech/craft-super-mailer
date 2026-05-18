<?php
namespace amici\SuperMailer\controllers;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\Plugin;
use Craft;
use craft\base\Element;
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

        return $this->renderTemplate('super-mailer/notifications/preview', [
            'notification' => $notification,
            'preview' => Plugin::getInstance()->getMailer()->preview($notification),
        ]);
    }
}
