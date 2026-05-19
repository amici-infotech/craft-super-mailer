<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\jobs\SendNotificationEmailJob;
use amici\SuperMailer\Plugin;
use Craft;
use craft\base\Element;
use craft\elements\Entry;
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

    public function previewEventContext(MailerNotification $notification, ?int $elementId = null): array
    {
        $class = (string)$notification->eventClass;
        $eventName = (string)$notification->eventName;
        $eventType = $this->eventType($class, $eventName);
        $previewElement = $elementId ? $this->previewElement($class, $eventType, $elementId) : null;
        $event = $this->previewEvent($notification, $eventType, $previewElement, $elementId);
        $context = $this->normalizeEvent($class, $eventName, $event);
        $context['eventConstant'] = (string)$notification->eventConstant;
        $context['preview'] = true;

        if ($elementId) {
            $context['previewElementId'] = $elementId;
            if (!$previewElement) {
                $context['previewError'] = Craft::t('super-mailer', 'No matching preview element found for ID {id}.', [
                    'id' => $elementId,
                ]);
            }
        }

        return $context;
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

        foreach (get_object_vars($event) as $value) {
            if ($value instanceof Element) {
                return $value;
            }
        }

        if ($event->sender instanceof Element) {
            return $event->sender;
        }

        return null;
    }

    private function elementData(Element $element): array
    {
        $data = [
            'id' => $element->id,
            'uid' => $element->uid,
            'type' => $element::class,
            'title' => (string)$element,
            'siteId' => $element->siteId,
            'status' => $element->getStatus(),
            'cpEditUrl' => $element->getCpEditUrl(),
            'dateUpdated' => $element->dateUpdated?->format('c'),
            'dateUpdatedFormatted' => $element->dateUpdated
                ? Craft::$app->getFormatter()->asDatetime($element->dateUpdated)
                : null,
            'attributes' => $this->scalarArray($element->getAttributes()),
            'fields' => $this->fieldData($element),
        ];

        if ($element instanceof Entry) {
            $author = $element->getAuthor();
            $data['authorId'] = $element->authorId;
            $data['authorName'] = $author
                ? (trim((string)$author->fullName) !== '' ? (string)$author->fullName : (string)$author->email)
                : null;
            $data['authorEmail'] = $author?->email;
        }

        return $data;
    }

    private function previewEvent(MailerNotification $notification, string $eventType, ?Element $previewElement = null, ?int $elementId = null): Event
    {
        $event = $this->createEvent($eventType);
        $event->name = (string)$notification->eventName;
        $event->sender = $this->previewSender((string)$notification->eventClass, $previewElement, $elementId);
        $event->data = [
            'preview' => true,
        ];

        $this->populatePreviewEventProperties($event, $previewElement, $elementId);

        return $event;
    }

    private function eventType(string $class, string $eventName): string
    {
        foreach (Plugin::getInstance()->getEvents()->getEvents() as $event) {
            if (($event['class'] ?? null) === $class && ($event['eventName'] ?? null) === $eventName) {
                return (string)($event['eventType'] ?? Event::class);
            }
        }

        return Event::class;
    }

    private function createEvent(string $eventType): Event
    {
        try {
            if (class_exists($eventType) && is_subclass_of($eventType, Event::class)) {
                return Craft::createObject($eventType);
            }
        } catch (Throwable) {
        }

        return new Event();
    }

    private function previewSender(string $class, ?Element $previewElement = null, ?int $elementId = null): mixed
    {
        if (is_subclass_of($class, Element::class)) {
            if ($previewElement instanceof $class) {
                return $previewElement;
            }

            if ($elementId) {
                return $class;
            }

            return $this->latestElement($class) ?? $class;
        }

        return $class;
    }

    private function populatePreviewEventProperties(Event $event, ?Element $previewElement = null, ?int $elementId = null): void
    {
        try {
            $reflection = new \ReflectionObject($event);
        } catch (Throwable) {
            return;
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                $this->populateScalarPreviewProperty($event, $property);
                continue;
            }

            $className = $type->getName();
            if (!is_subclass_of($className, Element::class)) {
                continue;
            }

            $element = null;

            if ($previewElement instanceof $className) {
                $element = $previewElement;
            }

            $element ??= $this->relatedPreviewElement($event, $property->getName(), $className);

            if (!$element && !$elementId) {
                $element = $this->latestElement($className);
            }

            if ($element) {
                $property->setValue($event, $element);
            }
        }
    }

    private function populateScalarPreviewProperty(Event $event, \ReflectionProperty $property): void
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return;
        }

        $name = $property->getName();
        if ($name === 'success' && $type->getName() === 'bool') {
            $property->setValue($event, true);
            return;
        }

        if ($name === 'isNew' && $type->getName() === 'bool') {
            $property->setValue($event, false);
            return;
        }

        if ($name === 'submitAction' && $type->getName() === 'string') {
            $property->setValue($event, 'submit');
        }
    }

    private function relatedPreviewElement(Event $event, string $propertyName, string $className): ?Element
    {
        foreach (get_object_vars($event) as $value) {
            if (!$value instanceof Element) {
                continue;
            }

            $getter = 'get' . ucfirst($propertyName);
            if (method_exists($value, $getter)) {
                try {
                    $related = $value->{$getter}();
                    if ($related instanceof $className) {
                        return $related;
                    }
                } catch (Throwable) {
                }
            }
        }

        return null;
    }

    private function previewElement(string $eventClass, string $eventType, int $elementId): ?Element
    {
        foreach ($this->previewElementClasses($eventClass, $eventType) as $className) {
            $element = $this->elementById($className, $elementId);
            if ($element) {
                return $element;
            }
        }

        return null;
    }

    private function previewElementClasses(string $eventClass, string $eventType): array
    {
        $classes = [];

        if (is_subclass_of($eventClass, Element::class)) {
            $classes[] = $eventClass;
        }

        try {
            if (class_exists($eventType)) {
                $reflection = new \ReflectionClass($eventType);
                foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                    $type = $property->getType();
                    if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                        continue;
                    }

                    $className = $type->getName();
                    if (is_subclass_of($className, Element::class)) {
                        $classes[] = $className;
                    }
                }
            }
        } catch (Throwable) {
        }

        return array_values(array_unique($classes));
    }

    private function elementById(string $className, int $elementId): ?Element
    {
        try {
            $query = $className::find();
            $query->id($elementId);
            $query->status(null);

            $element = $query->one();
            return $element instanceof Element ? $element : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function latestElement(string $className): ?Element
    {
        try {
            $query = $className::find();
            $query->status(null);

            try {
                $query->orderBy('dateUpdated DESC');
            } catch (Throwable) {
            }

            $element = $query->one();
            return $element instanceof Element ? $element : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fieldData(Element $element): array
    {
        try {
            return $this->scalarArray($element->getFieldValues());
        } catch (Throwable) {
            return [];
        }
    }

    private function scalarArray(array $value): array
    {
        $data = [];
        foreach ($value as $key => $item) {
            if ($item === null || is_scalar($item)) {
                $data[$key] = $item;
                continue;
            }

            if ($item instanceof \DateTimeInterface) {
                $data[$key] = $item->format('c');
                continue;
            }

            if (is_array($item)) {
                $data[$key] = $this->scalarArray($item);
            }
        }

        return $data;
    }
}
