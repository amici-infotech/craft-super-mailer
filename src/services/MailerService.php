<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\elements\MailerNotification;
use Craft;
use craft\helpers\App;
use Throwable;
use yii\base\Component;

class MailerService extends Component
{
    public function sendNotification(MailerNotification $notification, array $eventContext): bool
    {
        $variables = $this->variables($notification, $eventContext);
        $subject = $this->renderString((string)$notification->emailSubject, $variables);
        $html = $this->renderBody($notification->htmlTemplatePath, $variables);
        $text = $this->renderBody($notification->plainTextTemplatePath, $variables);

        if ($html === null && $text === null) {
            $text = $this->fallbackBody($notification, $eventContext);
        }

        $message = Craft::$app->getMailer()->compose();
        $message->setTo($this->renderEmailList((string)$notification->toEmails, $variables));

        $cc = $this->renderEmailList((string)$notification->ccEmails, $variables);
        if (!empty($cc)) {
            $message->setCc($cc);
        }

        $bcc = $this->renderEmailList((string)$notification->bccEmails, $variables);
        if (!empty($bcc)) {
            $message->setBcc($bcc);
        }

        $message->setFrom($this->fromAddress($notification));

        $replyTo = trim((string)$notification->replyTo);
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

        return $message->send();
    }

    public function preview(MailerNotification $notification): array
    {
        $context = $this->sampleEventContext($notification);
        $variables = $this->variables($notification, $context);

        return [
            'subject' => $this->renderString((string)$notification->emailSubject, $variables),
            'html' => $this->renderBody($notification->htmlTemplatePath, $variables),
            'text' => $this->renderBody($notification->plainTextTemplatePath, $variables)
                ?? $this->fallbackBody($notification, $context),
            'context' => $context,
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

    public function renderBody(?string $templatePath, array $variables): ?string
    {
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
        return [
            'notification' => $notification,
            'event' => $eventContext,
            'eventContext' => $eventContext,
            'craft' => Craft::$app->getView()->getTwig()->getGlobals()['craft'] ?? null,
        ];
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

    private function sampleEventContext(MailerNotification $notification): array
    {
        return [
            'eventClass' => (string)$notification->eventClass,
            'eventConstant' => (string)$notification->eventConstant,
            'eventName' => (string)$notification->eventName,
            'senderClass' => (string)$notification->eventClass,
            'isNew' => true,
            'element' => [
                'id' => 123,
                'type' => 'craft\\elements\\Entry',
                'title' => 'Example Entry',
                'siteId' => Craft::$app->getSites()->getCurrentSite()->id ?? null,
                'status' => 'enabled',
            ],
            'data' => [
                'sample' => true,
            ],
        ];
    }
}
