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

/**
 * Renders notification templates, sends emails through Craft mailer, previews output, and records delivery logs.
 */
class MailerService extends Component
{
    /**
     * Sends a notification using the normal recipient configuration and event context.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $eventContext eventContext value used by this method.
     * @return bool Return value produced by this method.
     */
    public function sendNotification(MailerNotification $notification, array $eventContext): bool
    {
        return $this->deliverNotification($notification, $eventContext);
    }

    /**
     * Sends a preview/test notification to a single override recipient.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $eventContext eventContext value used by this method.
     * @param string $email email value used by this method.
     * @return bool Return value produced by this method.
     */
    public function sendTestNotification(MailerNotification $notification, array $eventContext, string $email): bool
    {
        return $this->deliverNotification($notification, $eventContext, [$email]);
    }

    /**
     * Renders, addresses, sends, and logs one notification delivery attempt.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $eventContext eventContext value used by this method.
     * @param array $overrideTo overrideTo value used by this method.
     * @return bool Return value produced by this method.
     */
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

    /**
     * Builds a full preview payload for a notification, including recipients, body output, conditions, and context.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return array Return value produced by this method.
     */
    public function preview(MailerNotification $notification, ?int $elementId = null): array
    {
        $context = Plugin::getInstance()->getNotifications()->previewEventContext($notification, $elementId);
        $preview = $this->renderPreviewFromContext($notification, $context);
        $preview['rawContext'] = $context;
        $preview['conditions'] = Plugin::getInstance()->getNotifications()->conditionDebug($notification, $context);

        return $preview;
    }

    /**
     * Renders preview output from an existing serialized context, such as a log payload.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $context context value used by this method.
     * @return array Return value produced by this method.
     */
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

    /**
     * Parses a comma-separated email list into unique trimmed addresses.
     *
     * @param string $value value value used by this method.
     * @return array Return value produced by this method.
     */
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

    /**
     * Renders Twig in an email list field and parses the result into addresses.
     *
     * @param string $value value value used by this method.
     * @param array $variables variables value used by this method.
     * @return array Return value produced by this method.
     */
    public function renderEmailList(string $value, array $variables): array
    {
        return $this->parseEmailList($this->renderString($value, $variables));
    }

    /**
     * Renders an HTML or text email template from the site templates folder.
     *
     * @param string $templatePath templatePath value used by this method.
     * @param array $variables variables value used by this method.
     * @param string & $error error value used by this method.
     * @return ?string Return value produced by this method.
     */
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

    /**
     * Renders inline Twig strings such as subjects and recipient fields.
     *
     * @param string $template template value used by this method.
     * @param array $variables variables value used by this method.
     * @return string Return value produced by this method.
     */
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

    /**
     * Builds the Twig variable set used for previews and sends.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $eventContext eventContext value used by this method.
     * @return array Return value produced by this method.
     */
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

    /**
     * Wraps serialized event context in the template-facing EventContext object.
     *
     * @param array $eventContext eventContext value used by this method.
     * @return EventContext Return value produced by this method.
     */
    private function renderEventContext(array $eventContext): EventContext
    {
        $element = $this->contextElement($eventContext['element'] ?? null);

        return new EventContext($eventContext, $element);
    }

    /**
     * Rehydrates the element referenced by serialized event context when possible.
     *
     * @param mixed $elementData elementData value used by this method.
     * @return ?Element Return value produced by this method.
     */
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

    /**
     * Resolves the configured or default sender address for a notification.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @return string|array Return value produced by this method.
     */
    private function fromAddress(MailerNotification $notification): string|array
    {
        $settings = App::mailSettings();
        $defaultEmail = Craft::parseEnv((string)$settings->fromEmail);
        $defaultName = Craft::parseEnv((string)$settings->fromName);

        $email = trim((string)$notification->fromEmail) ?: $defaultEmail;
        $name = trim((string)$notification->fromName) ?: $defaultName;

        return $name !== '' ? [$email => $name] : $email;
    }

    /**
     * Extracts a useful mailer error message from the message or Craft logger.
     *
     * @param mixed $message message value used by this method.
     * @return ?string Return value produced by this method.
     */
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

    /**
     * Formats an exception message and stack trace for storage in email logs.
     *
     * @param Throwable $e e value used by this method.
     * @return string Return value produced by this method.
     */
    private function exceptionLog(Throwable $e): string
    {
        return $e->getMessage() . "\n\n" . $e::class . "\n" . $e->getTraceAsString();
    }

    /**
     * Finds the latest Craft mailer warning that explains a send failure.
     *
     * @return ?string Return value produced by this method.
     */
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

    /**
     * Writes the delivery attempt to the email log table and applies the configured retention policy.
     *
     * This is kept close to message delivery so every success or failure follows the same logging path,
     * including preview test sends and queued resend attempts.
     *
     * @param MailerNotification $notification Notification definition being delivered.
     * @param array $eventContext Serialized event context used for rendering.
     * @param string $status Delivery status stored on the log row.
     * @param string|null $error Failure detail, including stack traces when available.
     * @param array $messageData Rendered message metadata captured for log detail pages.
     */
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

    /**
     * Builds a plain text fallback body when no template body renders.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $eventContext eventContext value used by this method.
     * @return string Return value produced by this method.
     */
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
