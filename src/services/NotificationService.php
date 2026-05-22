<?php
namespace amici\SuperMailer\services;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\jobs\SendNotificationEmailJob;
use amici\SuperMailer\Plugin;
use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use Throwable;
use yii\base\Component;
use yii\base\Event;

/**
 * Registers event listeners, normalizes event context, evaluates conditions, and queues notification send jobs.
 */
class NotificationService extends Component
{
    private bool $_listenersRegistered = false;

    /**
     * Registers runtime event listeners for all currently enabled notification definitions.
     *
     * @return void Return value produced by this method.
     */
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
                if ($this->shouldIgnoreEvent($event)) {
                    return;
                }

                $context = $this->normalizeEvent($class, $eventName, $event);
                foreach ($notificationIds as $notificationId) {
                    $notification = MailerNotification::find()
                        ->id($notificationId)
                        ->status(null)
                        ->one();

                    if (!$notification instanceof MailerNotification || !$this->conditionsPass($notification, $event, $context)) {
                        continue;
                    }

                    Craft::$app->getQueue()->push(new SendNotificationEmailJob([
                        'notificationId' => $notificationId,
                        'eventContext' => $context,
                    ]));
                }
            });
        }
    }

    /**
     * Converts a live Yii event into serializable context that can be safely queued and logged.
     *
     * @param string $class class value used by this method.
     * @param string $eventName eventName value used by this method.
     * @param Event $event event value used by this method.
     * @return array Return value produced by this method.
     */
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
            'isNew' => $this->isNewEvent($event, $element, $data),
            'element' => $element ? $this->elementData($element) : null,
            'data' => $data,
            'time' => gmdate('c'),
        ];
    }

    /**
     * Builds a synthetic event context for previewing a notification before a real event fires.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return array Return value produced by this method.
     */
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

    /**
     * Returns detailed condition evaluation data for the preview condition debug table.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param array $context context value used by this method.
     * @return array Return value produced by this method.
     */
    public function conditionDebug(MailerNotification $notification, array $context): array
    {
        $rules = [];
        foreach ($notification->normalizedConditionRules() as $rule) {
            $field = (string)($rule['field'] ?? '');
            $actual = $this->conditionValue($field, $context);
            $expectedValues = $this->conditionExpectedValues((string)($rule['value'] ?? ''));

            if ($field === 'element.status') {
                $actual = $this->normalizeStatusConditionValue($actual);
                $expectedValues = array_values(array_filter(
                    array_map(fn(string $value): ?string => $this->normalizeStatusConditionValue($value), $expectedValues)
                ));
            }

            $rules[] = [
                'field' => $field,
                'operator' => (string)($rule['operator'] ?? 'equals'),
                'operatorLabel' => $this->conditionOperatorLabel((string)($rule['operator'] ?? 'equals')),
                'expected' => $expectedValues,
                'actual' => $actual,
                'passed' => $this->conditionRulePasses($rule, $context),
            ];
        }

        $phpCondition = trim((string)$notification->phpCondition);

        return [
            'matchMode' => $notification->conditionMatchMode,
            'rules' => $rules,
            'phpCondition' => $phpCondition,
            'phpConditionNote' => $phpCondition !== ''
                ? Craft::t('super-mailer', 'PHP conditions are evaluated against the live event object when the event fires.')
                : null,
            'passed' => $rules
                ? ($notification->conditionMatchMode === 'any'
                    ? in_array(true, array_column($rules, 'passed'), true)
                    : !in_array(false, array_column($rules, 'passed'), true))
                : true,
        ];
    }

    /**
     * Returns a human-readable label for a condition operator in preview/debug output.
     *
     * @param string $operator Stored condition operator value.
     * @return string Label shown in the Control Panel.
     */
    private function conditionOperatorLabel(string $operator): string
    {
        return match ($operator) {
            'contains' => Craft::t('super-mailer', 'contains'),
            'notContains' => Craft::t('super-mailer', 'does not contain'),
            'notEquals' => Craft::t('super-mailer', 'is not'),
            default => Craft::t('super-mailer', 'is'),
        };
    }

    /**
     * Handles should ignore event behavior for this Super Mailer component.
     *
     * @param Event $event event value used by this method.
     * @return bool Return value produced by this method.
     */
    private function shouldIgnoreEvent(Event $event): bool
    {
        $element = $this->eventElement($event);

        return $element instanceof Element && $this->isNonCanonicalElement($element);
    }

    /**
     * Evaluates configured table and PHP conditions to decide whether a notification should queue.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param Event $event event value used by this method.
     * @param array $context context value used by this method.
     * @return bool Return value produced by this method.
     */
    private function conditionsPass(MailerNotification $notification, Event $event, array $context): bool
    {
        $rules = $notification->normalizedConditionRules();
        $phpCondition = trim((string)$notification->phpCondition);
        $results = [];

        foreach ($rules as $rule) {
            $results[] = $this->conditionRulePasses($rule, $context);
        }

        if ($phpCondition !== '') {
            $results[] = $this->phpConditionPasses($phpCondition, $event);
        }

        if (!$results) {
            return true;
        }

        return $notification->conditionMatchMode === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /**
     * Evaluates one table condition row against normalized event context.
     *
     * @param array $rule rule value used by this method.
     * @param array $context context value used by this method.
     * @return bool Return value produced by this method.
     */
    private function conditionRulePasses(array $rule, array $context): bool
    {
        $field = (string)($rule['field'] ?? '');
        $actual = $this->conditionValue($field, $context);
        $expectedValues = $this->conditionExpectedValues((string)($rule['value'] ?? ''));
        $actualValues = $this->conditionActualValues($actual);

        if ($field === 'element.status') {
            $actual = $this->normalizeStatusConditionValue($actual);
            $expectedValues = array_values(array_filter(
                array_map(fn(string $value): ?string => $this->normalizeStatusConditionValue($value), $expectedValues)
            ));
            $actualValues = $actual !== null ? [(string)$actual] : [];
        }

        $operator = (string)($rule['operator'] ?? 'equals');

        if ($operator === 'contains') {
            return (bool)array_intersect($actualValues, $expectedValues);
        }

        if ($operator === 'notContains') {
            return !array_intersect($actualValues, $expectedValues);
        }

        $expected = (string)($expectedValues[0] ?? '');

        $matches = in_array($expected, $actualValues, true);

        return $operator === 'notEquals' ? !$matches : $matches;
    }

    /**
     * Reads the actual context value used by a condition row field.
     *
     * @param string $field field value used by this method.
     * @param array $context context value used by this method.
     * @return mixed Return value produced by this method.
     */
    private function conditionValue(string $field, array $context): mixed
    {
        $element = is_array($context['element'] ?? null) ? $context['element'] : [];
        $elementObject = $this->contextElement($context);

        return match ($field) {
            'element.status' => $this->statusConditionValue($element),
            'event.isNew' => !empty($context['isNew']) ? 'true' : 'false',
            'element.siteId' => isset($element['siteId']) ? (string)$element['siteId'] : null,
            'entry.authorId' => $elementObject instanceof Entry ? (string)$elementObject->authorId : ($element['authorId'] ?? null),
            'entry.type.handle' => $elementObject instanceof Entry ? ($elementObject->type->handle ?? null) : null,
            'entry.section.handle' => $elementObject instanceof Entry ? ($elementObject->section->handle ?? null) : null,
            default => $this->dynamicConditionValue($field, $context, $element, $elementObject),
        };
    }

    /**
     * Reads dynamic condition values generated from event properties, element properties, or field layouts.
     *
     * Event values are read from normalized scalar event data, custom fields prefer serialized field
     * context, and element properties fall back to the live rehydrated element when available.
     *
     * @param string $field Dynamic condition field identifier.
     * @param array $context Normalized event context.
     * @param array $element Serialized element context.
     * @param Element|null $elementObject Rehydrated live element.
     * @return mixed Dynamic value used for condition comparison.
     */
    private function dynamicConditionValue(string $field, array $context, array $element, ?Element $elementObject): mixed
    {
        if (str_starts_with($field, 'event.')) {
            $property = substr($field, 6);
            return $context['data'][$property] ?? null;
        }

        if (str_starts_with($field, 'field:')) {
            $handle = substr($field, 6);
            if (array_key_exists($handle, $element['fields'] ?? [])) {
                return $element['fields'][$handle];
            }

            return $this->elementPropertyValue($elementObject, $handle);
        }

        if (str_starts_with($field, 'element.')) {
            $property = substr($field, 8);
            if (array_key_exists($property, $element)) {
                return $element[$property];
            }

            if (array_key_exists($property, $element['attributes'] ?? [])) {
                return $element['attributes'][$property];
            }

            return $this->elementPropertyValue($elementObject, $property);
        }

        return null;
    }

    /**
     * Reads a public or magic property from an element without letting optional properties break sends.
     *
     * @param Element|null $element Element to inspect.
     * @param string $property Property or field handle to read.
     * @return mixed Property value or null when unavailable.
     */
    private function elementPropertyValue(?Element $element, string $property): mixed
    {
        if (!$element) {
            return null;
        }

        try {
            if ($property === 'typeId') {
                foreach (['getOwner', 'getProduct'] as $method) {
                    if (method_exists($element, $method)) {
                        $owner = $element->{$method}();
                        if ($owner instanceof Element && isset($owner->typeId)) {
                            return $owner->typeId;
                        }
                    }
                }
            }

            return $element->{$property} ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Converts scalar and array condition values into normalized string tokens for comparison.
     *
     * @param mixed $actual Raw value from event context.
     * @return array String comparison values.
     */
    private function conditionActualValues(mixed $actual): array
    {
        if ($actual === null) {
            return [];
        }

        if (is_bool($actual)) {
            return [$actual ? 'true' : 'false'];
        }

        if (is_scalar($actual)) {
            return [(string)$actual];
        }

        if ($actual instanceof \DateTimeInterface) {
            return [$actual->format('c')];
        }

        if ($actual instanceof \Traversable) {
            $values = [];
            foreach ($actual as $value) {
                foreach ($this->conditionActualValues($value) as $normalizedValue) {
                    $values[] = $normalizedValue;
                }
            }

            return array_values(array_unique($values));
        }

        if (is_array($actual)) {
            $values = [];
            foreach ($actual as $value) {
                foreach ($this->conditionActualValues($value) as $normalizedValue) {
                    $values[] = $normalizedValue;
                }
            }

            return array_values(array_unique($values));
        }

        if (is_object($actual) && property_exists($actual, 'value') && $actual->value !== null) {
            return [(string)$actual->value];
        }

        if ($actual instanceof \Stringable) {
            return [(string)$actual];
        }

        return [];
    }

    /**
     * Normalizes element status context into enabled or disabled values for status conditions.
     *
     * @param array $element element value used by this method.
     * @return string Return value produced by this method.
     */
    private function statusConditionValue(array $element): string
    {
        if (array_key_exists('enabled', $element)) {
            return !empty($element['enabled']) ? 'enabled' : 'disabled';
        }

        return $this->normalizeStatusConditionValue($element['status'] ?? null) ?? 'disabled';
    }

    /**
     * Converts common truthy, falsy, and Craft status strings into condition comparison values.
     *
     * @param mixed $value value value used by this method.
     * @return ?string Return value produced by this method.
     */
    private function normalizeStatusConditionValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }

        $value = strtolower(trim((string)$value));
        return match ($value) {
            '1', 'true', 'yes', 'on', 'enabled', 'live' => 'enabled',
            '0', 'false', 'no', 'off', 'disabled' => 'disabled',
            default => $value !== '' ? $value : null,
        };
    }

    /**
     * Parses a comma-separated condition value into trimmed comparison tokens.
     *
     * @param string $value value value used by this method.
     * @return array Return value produced by this method.
     */
    private function conditionExpectedValues(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }

    /**
     * Evaluates a custom PHP condition expression against the live event object.
     *
     * @param string $expression expression value used by this method.
     * @param Event $event event value used by this method.
     * @return bool Return value produced by this method.
     */
    private function phpConditionPasses(string $expression, Event $event): bool
    {
        try {
            return (bool)eval('return (bool)(' . $expression . ');');
        } catch (Throwable $e) {
            Craft::warning('Super Mailer PHP condition failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Rehydrates the element referenced by serialized event context when possible.
     *
     * @param array $context context value used by this method.
     * @return ?Element Return value produced by this method.
     */
    private function contextElement(array $context): ?Element
    {
        $elementData = is_array($context['element'] ?? null) ? $context['element'] : null;
        if (!$elementData) {
            return null;
        }

        $class = $elementData['type'] ?? null;
        $id = $elementData['id'] ?? null;
        if (!is_string($class) || !$id || !is_subclass_of($class, Element::class)) {
            return null;
        }

        try {
            $query = $class::find()->id((int)$id)->status(null);
            if (!empty($elementData['siteId']) && method_exists($query, 'siteId')) {
                $query->siteId((int)$elementData['siteId']);
            }

            $element = $query->one();
            return $element instanceof Element ? $element : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Checks whether notification records are available before listener registration runs.
     *
     * @return bool Return value produced by this method.
     */
    private function notificationTableExists(): bool
    {
        try {
            return Craft::$app->getDb()->getSchema()->getTableSchema('{{%super_mailer_notifications}}', true) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Finds the primary Craft element associated with a live event object.
     *
     * @param Event $event event value used by this method.
     * @return ?Element Return value produced by this method.
     */
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

        foreach (['getElement', 'getEntry', 'getAsset', 'getCategory', 'getUser', 'getSubmission'] as $method) {
            if (!method_exists($event, $method)) {
                continue;
            }

            try {
                $value = $event->{$method}();
                if ($value instanceof Element) {
                    return $value;
                }
            } catch (Throwable) {
            }
        }

        if ($event->sender instanceof Element) {
            return $event->sender;
        }

        return null;
    }

    /**
     * Serializes an element into queue-safe context while preserving useful attributes and field values.
     *
     * @param Element $element element value used by this method.
     * @return array Return value produced by this method.
     */
    private function elementData(Element $element): array
    {
        $fieldData = $this->fieldData($element);
        $data = [
            'id' => $element->id,
            'uid' => $element->uid,
            'type' => $element::class,
            'title' => (string)$element,
            'siteId' => $element->siteId,
            'status' => $element->getStatus(),
            'enabled' => (bool)$element->enabled && (bool)$element->getEnabledForSite(),
            'isDraft' => $element->getIsDraft(),
            'isRevision' => $element->getIsRevision(),
            'isDerivative' => $element->getIsDerivative(),
            'isProvisionalDraft' => $element->isProvisionalDraft,
            'firstSave' => (bool)$element->firstSave,
            'cpEditUrl' => $element->getCpEditUrl(),
            'dateUpdated' => $element->dateUpdated?->format('c'),
            'dateUpdatedFormatted' => $element->dateUpdated
                ? Craft::$app->getFormatter()->asDatetime($element->dateUpdated)
                : null,
            'attributes' => $this->scalarArray($element->getAttributes()),
            'fields' => $fieldData,
        ];

        foreach ($fieldData as $handle => $value) {
            if (!array_key_exists($handle, $data)) {
                $data[$handle] = $value;
            }
        }

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

    /**
     * Detects draft, revision, derivative, and provisional elements that should not trigger sends.
     *
     * @param Element $element element value used by this method.
     * @return bool Return value produced by this method.
     */
    private function isNonCanonicalElement(Element $element): bool
    {
        return ElementHelper::isDraftOrRevision($element)
            || $element->getIsDerivative()
            || $element->isProvisionalDraft;
    }

    /**
     * Determines whether an event represents newly created content.
     *
     * @param Event $event event value used by this method.
     * @param Element $element element value used by this method.
     * @param array $data data value used by this method.
     * @return bool Return value produced by this method.
     */
    private function isNewEvent(Event $event, ?Element $element, array $data): bool
    {
        if (!empty($data['isNew'])) {
            return true;
        }

        if ($element && $element->firstSave) {
            return true;
        }

        return $element instanceof Element && $this->requestIsFreshDraftApply($element);
    }

    /**
     * Detects Craft draft-apply requests that represent publishing fresh content.
     *
     * @param Element $element element value used by this method.
     * @return bool Return value produced by this method.
     */
    private function requestIsFreshDraftApply(Element $element): bool
    {
        try {
            $request = Craft::$app->getRequest();
            if ($request->getIsConsoleRequest()) {
                return false;
            }

            $action = (string)$request->getBodyParam('action');
            $fresh = $request->getBodyParam('fresh') ?? $request->getBodyParam('isFresh');
            $elementId = (int)$request->getBodyParam('elementId');

            return $action === 'elements/apply-draft'
                && (bool)$fresh
                && $elementId === (int)$element->id;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Creates and populates a synthetic event instance for preview rendering.
     *
     * @param MailerNotification $notification notification value used by this method.
     * @param string $eventType eventType value used by this method.
     * @param Element $previewElement previewElement value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return Event Return value produced by this method.
     */
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

    /**
     * Looks up the event object class configured for a notification event.
     *
     * @param string $class class value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return string Return value produced by this method.
     */
    private function eventType(string $class, string $eventName): string
    {
        foreach (Plugin::getInstance()->getEvents()->getEvents() as $event) {
            if (($event['class'] ?? null) === $class && ($event['eventName'] ?? null) === $eventName) {
                return (string)($event['eventType'] ?? Event::class);
            }
        }

        return Event::class;
    }

    /**
     * Instantiates an event class when possible, falling back to a base Yii event.
     *
     * @param string $eventType eventType value used by this method.
     * @return Event Return value produced by this method.
     */
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

    /**
     * Chooses an appropriate sender value for a preview event.
     *
     * @param string $class class value used by this method.
     * @param Element $previewElement previewElement value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return mixed Return value produced by this method.
     */
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

    /**
     * Fills public preview event properties with matching elements or scalar defaults.
     *
     * @param Event $event event value used by this method.
     * @param Element $previewElement previewElement value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return void Return value produced by this method.
     */
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

    /**
     * Sets safe scalar defaults on synthetic preview event properties.
     *
     * @param Event $event event value used by this method.
     * @param \ReflectionProperty $property property value used by this method.
     * @return void Return value produced by this method.
     */
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

    /**
     * Finds related elements from existing preview event values through matching getter methods.
     *
     * @param Event $event event value used by this method.
     * @param string $propertyName propertyName value used by this method.
     * @param string $className className value used by this method.
     * @return ?Element Return value produced by this method.
     */
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

    /**
     * Finds a preview element by ID from event-related element classes.
     *
     * @param string $eventClass eventClass value used by this method.
     * @param string $eventType eventType value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return ?Element Return value produced by this method.
     */
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

    /**
     * Determines which element classes may be valid preview targets for an event.
     *
     * @param string $eventClass eventClass value used by this method.
     * @param string $eventType eventType value used by this method.
     * @return array Return value produced by this method.
     */
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

    /**
     * Loads one element by ID while ignoring element status filters.
     *
     * @param string $className className value used by this method.
     * @param int $elementId elementId value used by this method.
     * @return ?Element Return value produced by this method.
     */
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

    /**
     * Loads the most recently updated element of a class for preview fallback.
     *
     * @param string $className className value used by this method.
     * @return ?Element Return value produced by this method.
     */
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

    /**
     * Extracts scalar custom field values from an element for serialized context.
     *
     * @param Element $element element value used by this method.
     * @return array Return value produced by this method.
     */
    private function fieldData(Element $element): array
    {
        try {
            return $this->scalarArray($element->getFieldValues());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Recursively filters values down to queue-safe scalar and date data.
     *
     * @param array $value value value used by this method.
     * @return array Return value produced by this method.
     */
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
