<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\jobs\SendNotificationEmailJob;
use Craft;
use craft\base\Element;
use Throwable;
use yii\base\Component;
use yii\base\Event;

class NotificationService extends Component
{
    private bool $_listenersRegistered = false;

    public function registerEnabledNotificationListeners(): void
    {
        if ($this->_listenersRegistered || !$this->notificationTableExists()) {
            return;
        }

        $this->_listenersRegistered = true;
        $groups = [];

        try {
            $notifications = MailerNotification::find()
                ->enabledNotification(true)
                ->status(null)
                ->all();
        } catch (Throwable $e) {
            Craft::warning('Could not load Super Mailer notifications: ' . $e->getMessage(), __METHOD__);
            return;
        }

        foreach ($notifications as $notification) {
            if (!$notification instanceof MailerNotification || !$notification->id || !$notification->eventClass || !$notification->eventName) {
                continue;
            }

            $class = (string)$notification->eventClass;
            $eventName = (string)$notification->eventName;
            $groups[$class . '::' . $eventName][] = (int)$notification->id;
        }

        foreach ($groups as $key => $notificationIds) {
            [$class, $eventName] = explode('::', $key, 2);
            Event::on($class, $eventName, function(Event $event) use ($class, $eventName, $notificationIds): void {
                $context = $this->normalizeEvent($class, $eventName, $event);
                foreach ($notificationIds as $notificationId) {
                    Craft::$app->getQueue()->push(new SendNotificationEmailJob([
                        'notificationId' => $notificationId,
                        'eventContext' => $context,
                    ]));
                }
            });
        }
    }

    public function normalizeEvent(string $class, string $eventName, Event $event): array
    {
        $element = $this->eventElement($event);
        $data = [];

        foreach (get_object_vars($event) as $property => $value) {
            if ($value === null || is_scalar($value)) {
                $data[$property] = $value;
                continue;
            }

            if (is_array($value)) {
                $data[$property] = $this->scalarArray($value);
            }
        }

        return [
            'eventClass' => $class,
            'eventName' => $eventName,
            'senderClass' => is_object($event->sender) ? $event->sender::class : (string)$event->sender,
            'isNew' => (bool)($data['isNew'] ?? false),
            'element' => $element ? $this->elementData($element) : null,
            'data' => $data,
            'time' => gmdate('c'),
        ];
    }

    private function notificationTableExists(): bool
    {
        try {
            return Craft::$app->getDb()->getSchema()->getTableSchema('{{%super_mailer_notifications}}', true) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function eventElement(Event $event): ?Element
    {
        foreach (['element', 'entry', 'asset', 'category', 'user'] as $property) {
            if (property_exists($event, $property) && $event->{$property} instanceof Element) {
                return $event->{$property};
            }
        }

        if ($event->sender instanceof Element) {
            return $event->sender;
        }

        return null;
    }

    private function elementData(Element $element): array
    {
        return [
            'id' => $element->id,
            'uid' => $element->uid,
            'type' => $element::class,
            'title' => (string)$element,
            'siteId' => $element->siteId,
            'status' => $element->getStatus(),
            'cpEditUrl' => $element->getCpEditUrl(),
        ];
    }

    private function scalarArray(array $value): array
    {
        $data = [];
        foreach ($value as $key => $item) {
            if ($item === null || is_scalar($item)) {
                $data[$key] = $item;
            }
        }

        return $data;
    }
}
