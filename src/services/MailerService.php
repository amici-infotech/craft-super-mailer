<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\behaviors\ElementContextBehavior;
use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\models\EventContext;
use amici\SuperMailer\Plugin;
use amici\SuperMailer\records\EmailLogRecord;
use Craft;
use craft\base\Element;
use craft\helpers\App;
use Throwable;
use yii\base\Component;

class MailerService extends Component
{
    public function sendNotification(MailerNotification $notification, array $eventContext): bool
    {
        return $this->deliverNotification($notification, $eventContext);
    }

    public function sendTestNotification(MailerNotification $notification, array $eventContext, string $email): bool
    {
        return $this->deliverNotification($notification, $eventContext, [$email]);
    }

    private function deliverNotification(MailerNotification $notification, array $eventContext, ?array $overrideTo = null): bool
    {
        $messageData = [];

        try {
            $variables = $this->variables($notification, $eventContext);
            $subject = $this->renderString((string)$notification->emailSubject, $variables);
            $html = $this->renderBody($notification->htmlTemplatePath, $variables);
            $text = $this->renderBody($notification->plainTextTemplatePath, $variables);

            if ($html === null && $text === null) {
                $text = $this->fallbackBody($notification, $eventContext);
            }

            $message = Craft::$app->getMailer()->compose();
            $to = $overrideTo ?? $this->renderEmailList((string)$notification->toEmails, $variables);
            $cc = $this->renderEmailList((string)$notification->ccEmails, $variables);
            $bcc = $this->renderEmailList((string)$notification->bccEmails, $variables);
            if ($overrideTo !== null) {
                $cc = [];
                $bcc = [];
            }
            $from = $this->fromAddress($notification);
            $replyTo = trim((string)$notification->replyTo);
            $messageData = [
                'subject' => $subject,
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'from' => $from,
                'replyTo' => $replyTo,
            ];

            $message->setTo($to);

            if (!empty($cc)) {
                $message->setCc($cc);
            }

            if (!empty($bcc)) {
                $message->setBcc($bcc);
            }

            $message->setFrom($from);

            if ($replyTo !== '') {
                $message->setReplyTo($replyTo);
            }

            $message->setSubject($subject);

            if ($html !== null) {
                $message->setHtmlBody($html);
            }

            if ($text !== null) {
                $message->setTextBody($text);
            }

            $sent = $message->send();
            if (!$sent) {
                $error = $this->messageError($message) ?: Craft::t('super-mailer', 'Mailer returned false.');
                $this->recordEmailLog($notification, $eventContext, EmailLogRecord::STATUS_FAILED, $error, $messageData);
                return false;
            }

            $this->recordEmailLog($notification, $eventContext, EmailLogRecord::STATUS_SUCCESS, null, $messageData);
            return true;
        } catch (Throwable $e) {
            $this->recordEmailLog($notification, $eventContext, EmailLogRecord::STATUS_FAILED, $this->exceptionLog($e), $messageData);
            return false;
        }
    }

    public function preview(MailerNotification $notification, ?int $elementId = null): array
    {
        $context = Plugin::getInstance()->getNotifications()->previewEventContext($notification, $elementId);
        $preview = $this->renderPreviewFromContext($notification, $context);
        $preview['rawContext'] = $context;
        $preview['conditions'] = Plugin::getInstance()->getNotifications()->conditionDebug($notification, $context);

        return $preview;
    }

    public function renderPreviewFromContext(MailerNotification $notification, array $context): array
    {
        $variables = $this->variables($notification, $context);
        $htmlError = null;
        $textError = null;
        $html = $this->renderBody($notification->htmlTemplatePath, $variables, $htmlError);
        $text = $this->renderBody($notification->plainTextTemplatePath, $variables, $textError);

        return [
            'subject' => $this->renderString((string)$notification->emailSubject, $variables),
            'recipients' => [
                'to' => $this->renderEmailList((string)$notification->toEmails, $variables),
                'cc' => $this->renderEmailList((string)$notification->ccEmails, $variables),
                'bcc' => $this->renderEmailList((string)$notification->bccEmails, $variables),
                'replyTo' => trim((string)$notification->replyTo),
                'from' => $this->fromAddress($notification),
            ],
            'html' => $html,
            'text' => $text ?? $this->fallbackBody($notification, $context),
            'errors' => array_filter([
                'html' => $htmlError,
                'text' => $textError,
            ]),
            'context' => $variables['event']->previewVariables(),
        ];
    }

    public function parseEmailList(string $value): array
    {
        $parts = explode(',', $value);
        $emails = [];

        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    public function renderEmailList(string $value, array $variables): array
    {
        return $this->parseEmailList($this->renderString($value, $variables));
    }

    public function renderBody(?string $templatePath, array $variables, ?string &$error = null): ?string
    {
        $error = null;
        $templatePath = trim((string)$templatePath);
        if ($templatePath === '') {
            return null;
        }

        $view = Craft::$app->getView();
        $previousPath = $view->getTemplatesPath();

        try {
            $view->setTemplatesPath(Craft::$app->getPath()->getSiteTemplatesPath());
            return $view->renderTemplate($templatePath, $variables);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Craft::warning('Super Mailer template render failed: ' . $e->getMessage(), __METHOD__);
            return null;
        } finally {
            $view->setTemplatesPath($previousPath);
        }
    }

    private function renderString(string $template, array $variables): string
    {
        if ($template === '') {
            return '';
        }

        try {
            return Craft::$app->getView()->renderString($template, $variables);
        } catch (Throwable) {
            return $template;
        }
    }

    private function variables(MailerNotification $notification, array $eventContext): array
    {
        $renderEvent = $this->renderEventContext($eventContext);

        return [
            'notification' => $notification,
            'event' => $renderEvent,
            'eventContext' => $renderEvent,
            'rawEventContext' => $eventContext,
            'craft' => Craft::$app->getView()->getTwig()->getGlobals()['craft'] ?? null,
        ];
    }

    private function renderEventContext(array $eventContext): EventContext
    {
        $element = $this->contextElement($eventContext['element'] ?? null);

        return new EventContext($eventContext, $element);
    }

    private function contextElement(mixed $elementData): ?Element
    {
        if (!is_array($elementData)) {
            return null;
        }

        $class = $elementData['type'] ?? null;
        $id = $elementData['id'] ?? null;

        if (!is_string($class) || !$id || !is_subclass_of($class, Element::class)) {
            return null;
        }

        try {
            $query = $class::find()
                ->id((int)$id)
                ->status(null);

            if (!empty($elementData['siteId']) && method_exists($query, 'siteId')) {
                $query->siteId((int)$elementData['siteId']);
            }

            $element = $query->one();
            if (!$element instanceof Element) {
                return null;
            }

            $element->attachBehavior('superMailerContext', new ElementContextBehavior([
                'data' => $elementData,
            ]));

            return $element;
        } catch (Throwable) {
            return null;
        }
    }

    private function fromAddress(MailerNotification $notification): string|array
    {
        $settings = App::mailSettings();
        $defaultEmail = Craft::parseEnv((string)$settings->fromEmail);
        $defaultName = Craft::parseEnv((string)$settings->fromName);

        $email = trim((string)$notification->fromEmail) ?: $defaultEmail;
        $name = trim((string)$notification->fromName) ?: $defaultName;

        return $name !== '' ? [$email => $name] : $email;
    }

    private function messageError(mixed $message): ?string
    {
        try {
            $error = $message->error ?? null;
            if ($error instanceof Throwable) {
                return $this->exceptionLog($error);
            }
        } catch (Throwable) {
        }

        $recentMailerError = $this->recentMailerError();
        return $recentMailerError ?: null;
    }

    private function exceptionLog(Throwable $e): string
    {
        return $e->getMessage() . "\n\n" . $e::class . "\n" . $e->getTraceAsString();
    }

    private function recentMailerError(): ?string
    {
        $messages = Craft::getLogger()->messages;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if (!is_array($message) || ($message[2] ?? null) !== 'craft\mail\Mailer::send') {
                continue;
            }

            $text = (string)($message[0] ?? '');
            if (str_contains($text, 'Error sending email:')) {
                return $text;
            }
        }

        return null;
    }

    private function recordEmailLog(
        MailerNotification $notification,
        array $eventContext,
        string $status,
        ?string $error,
        array $messageData
    ): void {
        Plugin::getInstance()->getLogs()->record($notification, $eventContext, $status, $error, $messageData);
        Plugin::getInstance()->getLogs()->purgeByRetention();
    }

    private function fallbackBody(MailerNotification $notification, array $eventContext): string
    {
        $lines = [
            'Super Mailer notification: ' . $notification->title,
            'Event: ' . ($eventContext['eventClass'] ?? '') . '::' . ($eventContext['eventName'] ?? ''),
            'Sender: ' . ($eventContext['senderClass'] ?? 'unknown'),
        ];

        if (!empty($eventContext['element']['id'])) {
            $lines[] = 'Element: ' . ($eventContext['element']['type'] ?? 'unknown') . ' #' . $eventContext['element']['id'];
        }

        return implode("\n", $lines);
    }

}
