<?php
namespace amici\SuperMailer\services;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\services\Elements;
use ReflectionClass;
use Solspace\Freeform\Elements\Submission as FreeformSubmission;
use Solspace\Freeform\Events\Submissions\ProcessSubmissionEvent;
use Throwable;
use yii\base\Component;

/**
 * Discovers supported Craft and plugin events and formats them for the notification event picker.
 */
class EventRegistryService extends Component
{
    private ?array $_events = null;
    private array $_conditionFields = [];
    private array $_elementConditionFields = [];
    private array $_fieldLayoutConditionFields = [];

    /**
     * Builds and caches the complete list of supported events available to notifications.
     *
     * @return array Return value produced by this method.
     */
    public function getEvents(): array
    {
        if ($this->_events !== null) {
            return $this->_events;
        }

        $events = [];
        foreach ($this->discoverEventDefinitions() as $definition) {
            $class = $definition['class'];
            $constant = $definition['constant'];
            $eventName = $definition['eventName'];
            if (!$this->isContentManagementEvent($class, $constant, $eventName)) {
                continue;
            }

            $key = $class . '::' . $eventName;
            $eventType = $definition['eventType'] ?? \yii\base\Event::class;
            $events[$key] = [
                'class' => $class,
                'constant' => $constant,
                'eventName' => $eventName,
                'eventType' => $eventType,
                'variables' => $this->eventVariables($eventType),
                'conditionFields' => $this->conditionFields($class, $eventType),
                'label' => $this->labelFor($class, $constant, $eventName),
                'value' => $this->encodeEventValue($class, $eventName, $constant),
                'code' => $this->exampleCode($class, $constant, $eventType),
            ];
        }

        uasort($events, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $this->_events = array_values($events);
    }

    /**
     * Formats supported events for the JavaScript event picker on the notification edit screen.
     *
     * @return array Return value produced by this method.
     */
    public function getSelectOptions(): array
    {
        $options = [];
        foreach ($this->getEvents() as $event) {
            $options[] = [
                'label' => $event['label'],
                'value' => $event['value'],
                'class' => $event['class'],
                'constant' => $event['constant'],
                'eventName' => $event['eventName'],
                'eventType' => $event['eventType'],
                'variables' => $event['variables'],
                'conditionFields' => $event['conditionFields'],
                'code' => $event['code'],
            ];
        }

        return $options;
    }

    /**
     * Decodes a stored event picker value and returns the matching event definition.
     *
     * @param string $value value value used by this method.
     * @return ?array Return value produced by this method.
     */
    public function getEventByValue(?string $value): ?array
    {
        $decoded = $this->decodeEventValue($value);
        if ($decoded === null) {
            return null;
        }

        foreach ($this->getEvents() as $event) {
            if ($event['class'] === $decoded['class'] && $event['eventName'] === $decoded['eventName']) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Encodes event identity data into the opaque value stored by the event picker field.
     *
     * @param string $class class value used by this method.
     * @param string $eventName eventName value used by this method.
     * @param string $constant constant value used by this method.
     * @return string Return value produced by this method.
     */
    public function encodeEventValue(string $class, string $eventName, string $constant): string
    {
        return base64_encode(json_encode([
            'class' => $class,
            'constant' => $constant,
            'eventName' => $eventName,
        ]));
    }

    /**
     * Decodes an event picker value back into class, constant, and event name parts.
     *
     * @param string $value value value used by this method.
     * @return ?array Return value produced by this method.
     */
    public function decodeEventValue(?string $value): ?array
    {
        if (!$value) {
            return null;
        }

        $decoded = json_decode(base64_decode($value, true) ?: '', true);
        if (!is_array($decoded) || empty($decoded['class']) || empty($decoded['eventName'])) {
            return null;
        }

        return [
            'class' => (string)$decoded['class'],
            'constant' => (string)($decoded['constant'] ?? ''),
            'eventName' => (string)$decoded['eventName'],
        ];
    }

    /**
     * Checks whether a submitted event class and event name are still available in the registry.
     *
     * @param string $class class value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return bool Return value produced by this method.
     */
    public function isValidEvent(string $class, string $eventName): bool
    {
        foreach ($this->getEvents() as $event) {
            if ($event['class'] === $class && $event['eventName'] === $eventName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collects raw event definitions from registered content element classes and known supported event classes.
     *
     * @return array Return value produced by this method.
     */
    private function discoverEventDefinitions(): array
    {
        $definitions = [];

        foreach ($this->registeredContentEventClasses() as $class) {
            foreach ($this->reflectedEventDefinitions($class) as $definition) {
                $definitions[$definition['class'] . '::' . $definition['eventName']] = $definition;
            }
        }

        ksort($definitions);

        return array_values($definitions);
    }

    /**
     * Determines whether a discovered event is relevant for content notification workflows.
     *
     * @param string $class class value used by this method.
     * @param string $constant constant value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return bool Return value produced by this method.
     */
    private function isContentManagementEvent(string $class, string $constant, string $eventName): bool
    {
        if ($this->isKnownPluginContentEvent($class, $constant, $eventName)) {
            return true;
        }

        if (!str_starts_with($constant, 'EVENT_AFTER_')) {
            return false;
        }

        if ($class === Elements::class) {
            return $this->isElementLifecycleConstant($constant, $eventName);
        }

        if (in_array($class, $this->registeredContentEventClasses(), true)) {
            return $this->isElementLifecycleConstant($constant, $eventName);
        }

        return false;
    }

    /**
     * Recognizes explicitly supported third-party plugin content events that do not follow Craft lifecycle names.
     *
     * @param string $class class value used by this method.
     * @param string $constant constant value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return bool Return value produced by this method.
     */
    private function isKnownPluginContentEvent(string $class, string $constant, string $eventName): bool
    {
        return class_exists(FreeformSubmission::class)
            && $class === FreeformSubmission::class
            && $constant === 'EVENT_PROCESS_SUBMISSION'
            && $eventName === FreeformSubmission::EVENT_PROCESS_SUBMISSION;
    }

    /**
     * Checks whether an event constant represents an element lifecycle event Super Mailer should expose.
     *
     * @param string $constant constant value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return bool Return value produced by this method.
     */
    private function isElementLifecycleConstant(string $constant, string $eventName): bool
    {
        if (in_array($constant, [
            'EVENT_AFTER_SAVE',
            'EVENT_AFTER_SAVE_ELEMENT',
            'EVENT_AFTER_DELETE',
            'EVENT_AFTER_DELETE_ELEMENT',
            'EVENT_AFTER_RESTORE',
            'EVENT_AFTER_RESTORE_ELEMENT',
            'EVENT_AFTER_PROPAGATE',
            'EVENT_AFTER_PROPAGATE_ELEMENT',
            'EVENT_AFTER_MOVE_IN_STRUCTURE',
            'EVENT_AFTER_DELETE_FOR_SITE',
        ], true)) {
            return true;
        }

        return false;
    }

    /**
     * Returns Craft element/service classes that should be scanned for notification event constants.
     *
     * @return array Return value produced by this method.
     */
    private function registeredContentEventClasses(): array
    {
        static $classes = null;
        if ($classes !== null) {
            return $classes;
        }

        $classes = [Elements::class];

        try {
            foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
                if ($elementType === \amici\SuperMailer\elements\MailerNotification::class) {
                    continue;
                }

                if (class_exists($elementType) && is_subclass_of($elementType, Element::class)) {
                    $classes[] = $elementType;
                }
            }
        } catch (Throwable) {
        }

        if (class_exists(FreeformSubmission::class)) {
            $classes[] = FreeformSubmission::class;
        }

        return $classes = array_values(array_unique($classes));
    }

    /**
     * Reflects a class and extracts its public event constants with their event types.
     *
     * @param string $class class value used by this method.
     * @return array Return value produced by this method.
     */
    private function reflectedEventDefinitions(string $class): array
    {
        try {
            if (!class_exists($class) && !interface_exists($class)) {
                return [];
            }

            $reflection = new ReflectionClass($class);
            $definitions = [];
            foreach ($reflection->getReflectionConstants() as $constant) {
                $name = $constant->getName();
                $value = $constant->getValue();

                if (!str_starts_with($name, 'EVENT_') || !is_string($value) || $value === '') {
                    continue;
                }

                $definitions[] = [
                    'class' => $class,
                    'constant' => $name,
                    'eventName' => $value,
                    'eventType' => $this->knownPluginEventType($class, $name, $value)
                        ?? $this->eventTypeFromReflectionConstant($constant),
                ];
            }

            return $definitions;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Reads a PHP namespace declaration from source text when resolving docblock event types.
     *
     * @param string $contents contents value used by this method.
     * @return string Return value produced by this method.
     */
    private function namespaceFromContents(string $contents): string
    {
        if (preg_match('/^\s*namespace\s+([^;{]+)[;{]/m', $contents, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * Reads PHP use statements from source text so short docblock class names can be resolved.
     *
     * @param string $contents contents value used by this method.
     * @return array Return value produced by this method.
     */
    private function usesFromContents(string $contents): array
    {
        preg_match_all('/^\s*use\s+([^;]+);/m', $contents, $matches);
        $uses = [];

        foreach ($matches[1] ?? [] as $use) {
            $use = trim($use);
            if (str_contains($use, ' function ') || str_contains($use, ' const ')) {
                continue;
            }

            $alias = null;
            if (preg_match('/^(.+)\s+as\s+([^\\\\]+)$/i', $use, $aliasMatch)) {
                $use = trim($aliasMatch[1]);
                $alias = trim($aliasMatch[2]);
            }

            $shortName = $alias ?: substr($use, (int)strrpos($use, '\\') + 1);
            $uses[$shortName] = ltrim($use, '\\');
        }

        return $uses;
    }

    /**
     * Extracts an @event type from a constant docblock and resolves it to a fully qualified class name.
     *
     * @param string $docblock docblock value used by this method.
     * @param string $namespace namespace value used by this method.
     * @param array $uses uses value used by this method.
     * @return string Return value produced by this method.
     */
    private function eventTypeFromDocblock(string $docblock, string $namespace, array $uses): string
    {
        if (preg_match('/@event\s+([\\\\a-zA-Z0-9_]+)/', $docblock, $match)) {
            return $this->resolveClassName($match[1], $namespace, $uses);
        }

        return \yii\base\Event::class;
    }

    /**
     * Returns a concrete event class for explicitly supported plugin events when docblocks are not enough.
     *
     * @param string $class class value used by this method.
     * @param string $constant constant value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return ?string Return value produced by this method.
     */
    private function knownPluginEventType(string $class, string $constant, string $eventName): ?string
    {
        if (
            class_exists(FreeformSubmission::class)
            && class_exists(ProcessSubmissionEvent::class)
            && $class === FreeformSubmission::class
            && $constant === 'EVENT_PROCESS_SUBMISSION'
            && $eventName === FreeformSubmission::EVENT_PROCESS_SUBMISSION
        ) {
            return ProcessSubmissionEvent::class;
        }

        return null;
    }

    /**
     * Resolves the event object class associated with a reflected event constant.
     *
     * @param \ReflectionClassConstant $constant constant value used by this method.
     * @return string Return value produced by this method.
     */
    private function eventTypeFromReflectionConstant(\ReflectionClassConstant $constant): string
    {
        $declaringClass = $constant->getDeclaringClass();
        $namespace = $declaringClass->getNamespaceName();
        $uses = [];
        $fileName = $declaringClass->getFileName();

        if (is_string($fileName)) {
            $contents = @file_get_contents($fileName);
            if (is_string($contents)) {
                $uses = $this->usesFromContents($contents);
            }
        }

        return $this->eventTypeFromDocblock($constant->getDocComment() ?: '', $namespace, $uses);
    }

    /**
     * Resolves short, imported, or fully qualified class names relative to a namespace and use map.
     *
     * @param string $type type value used by this method.
     * @param string $namespace namespace value used by this method.
     * @param array $uses uses value used by this method.
     * @return string Return value produced by this method.
     */
    private function resolveClassName(string $type, string $namespace, array $uses): string
    {
        $type = trim($type, '\\');
        if ($type === '') {
            return \yii\base\Event::class;
        }

        if (str_contains($type, '\\')) {
            return $type;
        }

        if (isset($uses[$type])) {
            return $uses[$type];
        }

        if ($type === 'Event') {
            return \yii\base\Event::class;
        }

        return $namespace !== '' ? $namespace . '\\' . $type : $type;
    }

    /**
     * Builds the variable list shown for a selected event in the notification editor.
     *
     * @param string $eventType eventType value used by this method.
     * @return array Return value produced by this method.
     */
    private function eventVariables(string $eventType): array
    {
        $variables = [
            [
                'name' => '$event->name',
                'type' => 'string|null',
                'description' => 'The event name.',
            ],
            [
                'name' => '$event->sender',
                'type' => 'mixed',
                'description' => 'The object or class that triggered the event.',
            ],
            [
                'name' => '$event->handled',
                'type' => 'bool',
                'description' => 'Whether another handler has handled the event.',
            ],
            [
                'name' => '$event->data',
                'type' => 'mixed',
                'description' => 'Custom data passed when the handler was attached.',
            ],
        ];

        try {
            if (!class_exists($eventType)) {
                return $variables;
            }

            $reflection = new ReflectionClass($eventType);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $name = '$event->' . $property->getName();
                if ($this->hasVariable($variables, $name)) {
                    continue;
                }

                $variables[] = [
                    'name' => $name,
                    'type' => $property->hasType() ? (string)$property->getType() : 'mixed',
                    'description' => $this->propertyDescription($property->getDocComment() ?: ''),
                ];
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $methodName = $method->getName();
                if (!str_starts_with($methodName, 'get')) {
                    continue;
                }

                $name = '$event->' . $methodName . '()';
                if ($this->hasVariable($variables, $name)) {
                    continue;
                }

                $variables[] = [
                    'name' => $name,
                    'type' => $method->hasReturnType() ? (string)$method->getReturnType() : 'mixed',
                    'description' => $this->methodDescription($method->getDocComment() ?: ''),
                ];
            }
        } catch (Throwable) {
        }

        usort($variables, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $variables;
    }

    /**
     * Builds the condition field metadata exposed to the condition builder for one selected event.
     *
     * The returned list is intentionally event-specific. It starts with useful scalar public event
     * properties, then adds element-level filters for the primary element class, and finally adds
     * custom field layout filters with UI hints and option values where Craft exposes them.
     *
     * @param string $class Event listener class selected in the event picker.
     * @param string $eventType Concrete Yii event class dispatched for that listener.
     * @return array Condition field definitions consumed by the editor JavaScript.
     */
    private function conditionFields(string $class, string $eventType): array
    {
        $cacheKey = $class . '|' . $eventType;
        if (isset($this->_conditionFields[$cacheKey])) {
            return $this->_conditionFields[$cacheKey];
        }

        $fields = [[
            'label' => Craft::t('super-mailer', 'Event: Is New'),
            'value' => 'event.isNew',
            'type' => 'booleanToggle',
            'expression' => '(bool)($event->isNew ?? false)',
        ]];

        $fields = array_merge($fields, $this->eventPropertyConditionFields($eventType));

        foreach ($this->conditionElementClasses($class, $eventType) as $elementClass) {
            $fields = array_merge($fields, $this->elementConditionFields($elementClass));

            if (!$this->isFormElementClass($elementClass)) {
                $fields = array_merge($fields, $this->fieldLayoutConditionFields($elementClass));
            }
        }

        return $this->_conditionFields[$cacheKey] = $this->uniqueConditionFields($fields);
    }

    /**
     * Creates filter definitions for scalar public variables on the event object.
     *
     * Object and array properties are omitted because they usually need custom code, while scalar
     * and boolean properties can be represented cleanly by the condition table.
     *
     * @param string $eventType Concrete Yii event class to inspect.
     * @return array Event property filter definitions.
     */
    private function eventPropertyConditionFields(string $eventType): array
    {
        $fields = [];

        try {
            if (!class_exists($eventType)) {
                return $fields;
            }

            $reflection = new ReflectionClass($eventType);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $name = $property->getName();
                if (in_array($name, ['sender', 'name', 'handled', 'data', 'isValid'], true)) {
                    continue;
                }

                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    continue;
                }

                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Event: {name}', ['name' => $this->titleFromHandle($name)]),
                    'value' => 'event.' . $name,
                    'type' => $this->conditionInputTypeFromPhpType($typeName),
                    'expression' => '($event->' . $name . ' ?? null)',
                    'placeholder' => $typeName === 'bool' ? '' : $name,
                ];
            }
        } catch (Throwable) {
        }

        return $fields;
    }

    /**
     * Finds element classes that can reasonably provide element attributes and field layout filters.
     *
     * The listener class itself is used when it is an element type. The event type is also inspected for
     * public element properties so events like submission-processing events can expose their submission
     * element fields without hardcoding each plugin's internals.
     *
     * @param string $class Selected event listener class.
     * @param string $eventType Concrete Yii event class for the event.
     * @return array Unique element class names.
     */
    private function conditionElementClasses(string $class, string $eventType): array
    {
        $classes = [];

        if (is_subclass_of($class, Element::class)) {
            $classes[] = $class;
        }

        try {
            if (class_exists($eventType)) {
                $reflection = new ReflectionClass($eventType);
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
     * Builds element-level filters such as status, site, author, section, and declared public properties.
     *
     * Craft's status filter is only shown when the element type advertises statuses. Entry-specific
     * filters are only returned for Entry events, while custom element public properties are discovered
     * generically when they are scalar enough for a table condition.
     *
     * @param string $elementClass Element class being inspected.
     * @return array Element filter definitions.
     */
    private function elementConditionFields(string $elementClass): array
    {
        if (isset($this->_elementConditionFields[$elementClass])) {
            return $this->_elementConditionFields[$elementClass];
        }

        $fields = [];

        try {
            if ($this->isCommerceElementClass($elementClass)) {
                return $this->_elementConditionFields[$elementClass] = $this->commerceElementConditionFields($elementClass);
            }

            if ($this->isFormElementClass($elementClass)) {
                return $this->_elementConditionFields[$elementClass] = $this->formElementConditionFields($elementClass);
            }

            if ($this->isSubmissionElementClass($elementClass)) {
                return $this->_elementConditionFields[$elementClass] = $this->submissionElementConditionFields($elementClass);
            }

            if (method_exists($elementClass, 'hasStatuses') && $elementClass::hasStatuses()) {
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Element: Status'),
                    'value' => 'element.status',
                    'type' => 'toggle',
                    'expression' => '(($event->sender->enabled ?? false) ? \'enabled\' : \'disabled\')',
                ];
            }

            if (method_exists($elementClass, 'isLocalized') && $elementClass::isLocalized()) {
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Element: Site'),
                    'value' => 'element.siteId',
                    'type' => 'selectize',
                    'options' => $this->siteOptions(),
                    'expression' => '((string)($event->sender->siteId ?? \'\'))',
                ];
            }

            if (is_a($elementClass, Category::class, true)) {
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Category: Group'),
                    'value' => 'element.groupId',
                    'type' => 'selectize',
                    'options' => $this->categoryGroupOptions(),
                    'expression' => '((string)($event->sender->groupId ?? \'\'))',
                ];
            }

            if (is_a($elementClass, Entry::class, true)) {
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Entry: Section'),
                    'value' => 'entry.section.handle',
                    'type' => 'selectize',
                    'options' => $this->sectionOptions(),
                    'expression' => '($event->sender->section->handle ?? null)',
                ];
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Entry: Entry Type'),
                    'value' => 'entry.type.handle',
                    'type' => 'selectize',
                    'options' => $this->entryTypeOptions(),
                    'expression' => '($event->sender->type->handle ?? null)',
                ];
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Entry: Author'),
                    'value' => 'entry.authorId',
                    'type' => 'author',
                    'expression' => '((string)($event->sender->authorId ?? \'\'))',
                ];
            }

            $reflection = new ReflectionClass($elementClass);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() === Element::class) {
                    continue;
                }

                $name = $property->getName();
                if ($this->isIgnoredElementProperty($name)) {
                    continue;
                }

                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    continue;
                }

                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Element: {name}', ['name' => $this->titleFromHandle($name)]),
                    'value' => 'element.' . $name,
                    'type' => $this->conditionInputTypeFromPhpType($typeName),
                    'expression' => '($event->sender->' . $name . ' ?? null)',
                    'placeholder' => $typeName === 'bool' ? '' : $name,
                ];
            }
        } catch (Throwable) {
        }

        return $this->_elementConditionFields[$elementClass] = $fields;
    }

    /**
     * Builds a deliberately small filter set for form definition elements.
     *
     * Form element records expose many administrative settings as public properties. Those are useful for
     * plugin internals but noisy for notification filters, so the condition table only exposes the form
     * handle with known handles preloaded.
     *
     * @param string $elementClass Form element class.
     * @return array Form filter definitions.
     */
    private function formElementConditionFields(string $elementClass): array
    {
        return [
            [
                'label' => Craft::t('super-mailer', 'Form: Handle'),
                'value' => 'element.handle',
                'type' => 'selectize',
                'options' => $this->formHandleOptions($elementClass),
                'expression' => '($event->sender->handle ?? null)',
            ],
        ];
    }

    /**
     * Builds a deliberately small filter set for submission elements.
     *
     * Submission element records contain tracking IDs, request metadata, and storage internals. The
     * filter UI only exposes the practical business filters: submission status, form, and submitting user.
     *
     * @param string $elementClass Submission element class.
     * @return array Submission filter definitions.
     */
    private function submissionElementConditionFields(string $elementClass): array
    {
        return [
            [
                'label' => Craft::t('super-mailer', 'Submission: Status'),
                'value' => 'element.statusId',
                'type' => 'selectize',
                'options' => $this->submissionStatusOptions($elementClass),
                'expression' => '((string)($event->sender->statusId ?? \'\'))',
            ],
            [
                'label' => Craft::t('super-mailer', 'Submission: Form'),
                'value' => 'element.formId',
                'type' => 'selectize',
                'options' => $this->formIdOptions($elementClass),
                'expression' => '((string)($event->sender->formId ?? \'\'))',
            ],
            [
                'label' => Craft::t('super-mailer', 'Submission: User'),
                'value' => 'element.userId',
                'type' => 'author',
                'expression' => '((string)($event->sender->userId ?? \'\'))',
            ],
        ];
    }

    /**
     * Builds curated filters for Craft Commerce element classes.
     *
     * Commerce elements expose many calculated prices, dimensions, snapshots, and operational fields as
     * public or magic properties. The condition table only exposes values that map to real business
     * decisions and can be compared reliably from event context.
     *
     * @param string $elementClass Commerce element class being inspected.
     * @return array Commerce-specific condition field definitions.
     */
    private function commerceElementConditionFields(string $elementClass): array
    {
        $fields = [];

        if (method_exists($elementClass, 'hasStatuses') && $elementClass::hasStatuses()) {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Element: Status'),
                'value' => 'element.status',
                'type' => 'toggle',
                'expression' => '(($event->sender->enabled ?? false) ? \'enabled\' : \'disabled\')',
            ];
        }

        if (method_exists($elementClass, 'isLocalized') && $elementClass::isLocalized()) {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Element: Site'),
                'value' => 'element.siteId',
                'type' => 'selectize',
                'options' => $this->siteOptions(),
                'expression' => '((string)($event->sender->siteId ?? \'\'))',
            ];
        }

        if (in_array($elementClass, ['craft\\commerce\\elements\\Product', 'craft\\commerce\\elements\\Variant'], true)) {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Commerce: Product Type'),
                'value' => 'element.typeId',
                'type' => 'selectize',
                'options' => $this->commerceProductTypeOptions(),
                'expression' => '((string)($event->sender->typeId ?? $event->sender->owner->typeId ?? \'\'))',
            ];
        }

        if (in_array($elementClass, [
            'craft\\commerce\\elements\\Product',
            'craft\\commerce\\elements\\Variant',
            'craft\\commerce\\elements\\Donation',
            'craft\\commerce\\elements\\Order',
        ], true)) {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Commerce: Store'),
                'value' => 'element.storeId',
                'type' => 'selectize',
                'options' => $this->commerceStoreOptions(),
                'expression' => '((string)($event->sender->storeId ?? \'\'))',
            ];
        }

        if ($elementClass === 'craft\\commerce\\elements\\Variant') {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Variant: SKU'),
                'value' => 'element.sku',
                'type' => 'text',
                'expression' => '($event->sender->sku ?? null)',
                'placeholder' => 'ABC-123',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Variant: Is Default'),
                'value' => 'element.isDefault',
                'type' => 'booleanToggle',
                'expression' => '(bool)($event->sender->isDefault ?? false)',
            ];
        }

        if ($elementClass === 'craft\\commerce\\elements\\Donation') {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Donation: Available for Purchase'),
                'value' => 'element.availableForPurchase',
                'type' => 'booleanToggle',
                'expression' => '(bool)($event->sender->availableForPurchase ?? false)',
            ];
        }

        if ($elementClass === 'craft\\commerce\\elements\\Order') {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Order: Status'),
                'value' => 'element.orderStatusId',
                'type' => 'selectize',
                'options' => $this->commerceOrderStatusOptions(),
                'expression' => '((string)($event->sender->orderStatusId ?? \'\'))',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Order: Customer'),
                'value' => 'element.customerId',
                'type' => 'author',
                'expression' => '((string)($event->sender->customerId ?? \'\'))',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Order: Email'),
                'value' => 'element.email',
                'type' => 'text',
                'expression' => '($event->sender->email ?? null)',
                'placeholder' => 'customer@example.com',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Order: Is Completed'),
                'value' => 'element.isCompleted',
                'type' => 'booleanToggle',
                'expression' => '(bool)($event->sender->isCompleted ?? false)',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Order: Is Paid'),
                'value' => 'element.isPaid',
                'type' => 'booleanToggle',
                'expression' => '(bool)($event->sender->isPaid ?? false)',
            ];
        }

        if ($elementClass === 'craft\\commerce\\elements\\Subscription') {
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Subscription: User'),
                'value' => 'element.userId',
                'type' => 'author',
                'expression' => '((string)($event->sender->userId ?? \'\'))',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Subscription: Plan'),
                'value' => 'element.planId',
                'type' => 'selectize',
                'options' => $this->commercePlanOptions(),
                'expression' => '((string)($event->sender->planId ?? \'\'))',
            ];
            $fields[] = [
                'label' => Craft::t('super-mailer', 'Subscription: Gateway'),
                'value' => 'element.gatewayId',
                'type' => 'selectize',
                'options' => $this->commerceGatewayOptions(),
                'expression' => '((string)($event->sender->gatewayId ?? \'\'))',
            ];
            foreach ([
                'onTrial' => 'On Trial',
                'isCanceled' => 'Is Canceled',
                'isSuspended' => 'Is Suspended',
                'isExpired' => 'Is Expired',
            ] as $property => $label) {
                $fields[] = [
                    'label' => Craft::t('super-mailer', 'Subscription: {label}', ['label' => $label]),
                    'value' => 'element.' . $property,
                    'type' => 'booleanToggle',
                    'expression' => '(bool)($event->sender->' . $property . ' ?? false)',
                ];
            }
        }

        return $fields;
    }

    /**
     * Builds condition metadata for custom fields attached to an element type's field layouts.
     *
     * Multiple layouts can contain the same field, so the result is de-duplicated later by condition
     * value. Option-based fields expose their configured values to the UI, while text-like fields stay as
     * normal text inputs.
     *
     * @param string $elementClass Element class whose layouts should be inspected.
     * @return array Custom field filter definitions.
     */
    private function fieldLayoutConditionFields(string $elementClass): array
    {
        if (isset($this->_fieldLayoutConditionFields[$elementClass])) {
            return $this->_fieldLayoutConditionFields[$elementClass];
        }

        $fields = [];

        try {
            $layouts = Craft::$app->getFields()->getLayoutsByType($elementClass);
            foreach ($layouts as $layout) {
                foreach ($layout->getCustomFields() as $field) {
                    if ($field instanceof FieldInterface) {
                        $fields[] = $this->customFieldConditionField($field);
                    }
                }
            }
        } catch (Throwable) {
        }

        return $this->_fieldLayoutConditionFields[$elementClass] = $fields;
    }

    /**
     * Converts a Craft custom field into one condition field definition for the UI and evaluator.
     *
     * @param FieldInterface $field Custom field instance from a field layout.
     * @return array Condition metadata for the field.
     */
    private function customFieldConditionField(FieldInterface $field): array
    {
        $definition = [
            'label' => Craft::t('super-mailer', 'Field: {name}', ['name' => $field->name]),
            'value' => 'field:' . $field->handle,
            'type' => $this->conditionInputTypeFromField($field),
            'expression' => '($event->sender->' . $field->handle . ' ?? null)',
            'placeholder' => $field->handle,
        ];

        $options = $this->fieldOptions($field);
        if ($options) {
            $definition['options'] = $options;
        }

        return $definition;
    }

    /**
     * Determines the best editor control for a custom field type.
     *
     * @param FieldInterface $field Custom field being inspected.
     * @return string Condition value editor type used by JavaScript.
     */
    private function conditionInputTypeFromField(FieldInterface $field): string
    {
        if (is_a($field, \craft\fields\Lightswitch::class)) {
            return 'booleanToggle';
        }

        if (is_a($field, \craft\fields\BaseOptionsField::class)) {
            return 'selectize';
        }

        if (is_a($field, \craft\fields\Number::class)) {
            return 'number';
        }

        if (is_a($field, \craft\fields\Date::class)) {
            return 'date';
        }

        return 'text';
    }

    /**
     * Maps reflected PHP scalar types to condition editor controls.
     *
     * @param string $typeName Reflected PHP type name.
     * @return string Condition value editor type.
     */
    private function conditionInputTypeFromPhpType(string $typeName): string
    {
        return match ($typeName) {
            'bool' => 'booleanToggle',
            'int', 'float' => 'number',
            default => 'text',
        };
    }

    /**
     * Extracts configured options from Craft option fields for selectable condition controls.
     *
     * @param FieldInterface $field Custom field being inspected.
     * @return array Selectable option labels and values.
     */
    private function fieldOptions(FieldInterface $field): array
    {
        if (!property_exists($field, 'options') || !is_array($field->options)) {
            return [];
        }

        $options = [];
        foreach ($field->options as $option) {
            if (!is_array($option) || !empty($option['optgroup'])) {
                continue;
            }

            $value = (string)($option['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $options[] = [
                'label' => (string)($option['label'] ?? $value),
                'value' => $value,
            ];
        }

        return $options;
    }

    /**
     * Checks whether a public element property is too internal or too noisy for condition filtering.
     *
     * @param string $property Public element property name.
     * @return bool Whether the property should be hidden from the condition builder.
     */
    private function isIgnoredElementProperty(string $property): bool
    {
        $normalized = strtolower($property);
        if (str_starts_with($normalized, 'default') || str_contains($normalized, 'sort')) {
            return true;
        }

        return in_array($property, [
            'title',
            'name',
            'description',
            'fieldLayoutId',
            'sectionId',
            'collapsed',
            'oldStatus',
            'deletedWithEntryType',
            'deletedWithSection',
            'deletedWithGroup',
            'placeInStructure',
            'fieldId',
            'saveOwnership',
            'updateSearchIndexForOwner',
            'layoutId',
            'templateId',
            'defaultVariantId',
            'defaultSku',
            'defaultBasePrice',
            'defaultBasePromotionalPrice',
            'defaultHeight',
            'defaultLength',
            'defaultWidth',
            'defaultWeight',
            'submitActionEntryId',
            'submitActionEntrySiteId',
            'dataRetention',
            'dataRetentionValue',
            'userDeletedAction',
            'fileUploadsAction',
            'resetClasses',
            'pageCount',
            'isApplyingStencil',
            'incrementalId',
            'token',
            'isHidden',
            'requestId',
            'ip',
            'sourceUrl',
            'idempotencyKey',
        ], true);
    }

    /**
     * Detects Craft Commerce element classes so they can use curated Commerce filters.
     *
     * @param string $elementClass Element class being inspected.
     * @return bool Whether the element belongs to Craft Commerce.
     */
    private function isCommerceElementClass(string $elementClass): bool
    {
        return str_starts_with($elementClass, 'craft\\commerce\\elements\\');
    }

    /**
     * Detects known form definition element classes without requiring optional plugins to be installed.
     *
     * @param string $elementClass Element class being inspected.
     * @return bool Whether the element represents a form definition.
     */
    private function isFormElementClass(string $elementClass): bool
    {
        return in_array($elementClass, [
            'verbb\\formie\\elements\\Form',
        ], true);
    }

    /**
     * Detects known submission element classes without requiring optional plugins to be installed.
     *
     * @param string $elementClass Element class being inspected.
     * @return bool Whether the element represents a submitted form record.
     */
    private function isSubmissionElementClass(string $elementClass): bool
    {
        return in_array($elementClass, [
            'verbb\\formie\\elements\\Submission',
            FreeformSubmission::class,
        ], true);
    }

    /**
     * Returns Craft Commerce product type options when Commerce is installed.
     *
     * @return array Selectable product type labels and IDs.
     */
    private function commerceProductTypeOptions(): array
    {
        try {
            if (!class_exists('craft\\commerce\\Plugin')) {
                return [];
            }

            return array_map(
                static fn($type): array => [
                    'label' => (string)($type->name ?? $type->handle ?? $type->id),
                    'value' => (string)$type->id,
                ],
                \craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns Craft Commerce store options when Commerce is installed.
     *
     * @return array Selectable store labels and IDs.
     */
    private function commerceStoreOptions(): array
    {
        try {
            if (!class_exists('craft\\commerce\\Plugin')) {
                return [];
            }

            $stores = \craft\commerce\Plugin::getInstance()->getStores()->getAllStores();
            return array_map(
                static fn($store): array => [
                    'label' => (string)($store->name ?? $store->handle ?? $store->id),
                    'value' => (string)$store->id,
                ],
                is_array($stores) ? $stores : $stores->all()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns Craft Commerce order status options when Commerce is installed.
     *
     * @return array Selectable order status labels and IDs.
     */
    private function commerceOrderStatusOptions(): array
    {
        try {
            if (!class_exists('craft\\commerce\\Plugin')) {
                return [];
            }

            $statuses = [];
            foreach ($this->commerceStoreOptions() as $store) {
                $storeStatuses = \craft\commerce\Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses((int)$store['value']);
                foreach (is_array($storeStatuses) ? $storeStatuses : $storeStatuses->all() as $status) {
                    $statuses[$status->id] = [
                        'label' => (string)($status->name ?? $status->handle ?? $status->id),
                        'value' => (string)$status->id,
                    ];
                }
            }

            return array_values($statuses);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns Craft Commerce subscription plan options when Commerce is installed.
     *
     * @return array Selectable plan labels and IDs.
     */
    private function commercePlanOptions(): array
    {
        try {
            if (!class_exists('craft\\commerce\\Plugin') || !method_exists(\craft\commerce\Plugin::getInstance(), 'getPlans')) {
                return [];
            }

            return array_map(
                static fn($plan): array => [
                    'label' => (string)($plan->name ?? $plan->handle ?? $plan->id),
                    'value' => (string)$plan->id,
                ],
                \craft\commerce\Plugin::getInstance()->getPlans()->getAllPlans()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns Craft Commerce gateway options when Commerce is installed.
     *
     * @return array Selectable gateway labels and IDs.
     */
    private function commerceGatewayOptions(): array
    {
        try {
            if (!class_exists('craft\\commerce\\Plugin')) {
                return [];
            }

            $gateways = \craft\commerce\Plugin::getInstance()->getGateways()->getAllGateways();
            return array_map(
                static fn($gateway): array => [
                    'label' => (string)($gateway->name ?? $gateway->handle ?? $gateway->id),
                    'value' => (string)$gateway->id,
                ],
                is_array($gateways) ? $gateways : $gateways->all()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns configured form handles for form definition condition filters.
     *
     * @param string $elementClass Form element class being inspected.
     * @return array Selectable form handle options.
     */
    private function formHandleOptions(string $elementClass): array
    {
        return array_map(
            static fn(array $option): array => [
                'label' => $option['label'],
                'value' => $option['handle'],
            ],
            $this->formOptions($elementClass)
        );
    }

    /**
     * Returns configured form IDs for submission condition filters.
     *
     * @param string $elementClass Submission element class being inspected.
     * @return array Selectable form ID options.
     */
    private function formIdOptions(string $elementClass): array
    {
        return array_map(
            static fn(array $option): array => [
                'label' => $option['label'],
                'value' => $option['id'],
            ],
            $this->formOptions($elementClass)
        );
    }

    /**
     * Returns known form options for supported form plugins.
     *
     * @param string $elementClass Element class that determines which integration to inspect.
     * @return array Form option arrays containing id, handle, and label.
     */
    private function formOptions(string $elementClass): array
    {
        if (str_starts_with($elementClass, 'verbb\\formie\\')) {
            return $this->formieFormOptions();
        }

        if ($elementClass === FreeformSubmission::class) {
            return $this->freeformFormOptions();
        }

        return [];
    }

    /**
     * Returns known submission status options for supported form plugins.
     *
     * @param string $elementClass Submission element class being inspected.
     * @return array Selectable submission status options.
     */
    private function submissionStatusOptions(string $elementClass): array
    {
        if (str_starts_with($elementClass, 'verbb\\formie\\')) {
            return $this->formieStatusOptions();
        }

        if ($elementClass === FreeformSubmission::class) {
            return $this->freeformStatusOptions();
        }

        return [];
    }

    /**
     * Reads Formie form definitions when Formie is installed.
     *
     * @return array Form option arrays containing id, handle, and label.
     */
    private function formieFormOptions(): array
    {
        try {
            if (!class_exists('verbb\\formie\\Formie')) {
                return [];
            }

            $forms = \verbb\formie\Formie::$plugin->getForms()->getAllForms();
            return array_map(
                static fn($form): array => [
                    'id' => (string)$form->id,
                    'handle' => (string)$form->handle,
                    'label' => (string)($form->title ?? $form->handle),
                ],
                $forms
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Reads Freeform form definitions when Freeform is installed.
     *
     * @return array Form option arrays containing id, handle, and label.
     */
    private function freeformFormOptions(): array
    {
        try {
            if (!class_exists('Solspace\\Freeform\\Freeform')) {
                return [];
            }

            $forms = \Solspace\Freeform\Freeform::getInstance()->forms->getAllForms();
            return array_map(
                static fn($form): array => [
                    'id' => (string)$form->getId(),
                    'handle' => (string)$form->getHandle(),
                    'label' => (string)$form->getName(),
                ],
                $forms
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Reads Formie submission statuses when Formie is installed.
     *
     * @return array Selectable status labels and IDs.
     */
    private function formieStatusOptions(): array
    {
        try {
            if (!class_exists('verbb\\formie\\Formie')) {
                return [];
            }

            return array_map(
                static fn($status): array => [
                    'label' => (string)($status->name ?? $status->handle ?? $status->id),
                    'value' => (string)$status->id,
                ],
                \verbb\formie\Formie::$plugin->getStatuses()->getAllStatuses()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Reads Freeform submission statuses when Freeform is installed.
     *
     * @return array Selectable status labels and IDs.
     */
    private function freeformStatusOptions(): array
    {
        try {
            if (!class_exists('Solspace\\Freeform\\Freeform')) {
                return [];
            }

            return array_map(
                static fn($status): array => [
                    'label' => (string)($status->name ?? $status->handle ?? $status->id),
                    'value' => (string)$status->id,
                ],
                \Solspace\Freeform\Freeform::getInstance()->statuses->getAllStatuses()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns category group options for Category condition filters.
     *
     * @return array Selectable category group labels and IDs.
     */
    private function categoryGroupOptions(): array
    {
        return array_map(
            static fn($group): array => [
                'label' => $group->name,
                'value' => (string)$group->id,
            ],
            Craft::$app->getCategories()->getAllGroups()
        );
    }

    /**
     * Returns site options for localized element filters.
     *
     * @return array Selectable site labels and IDs.
     */
    private function siteOptions(): array
    {
        return array_map(
            static fn($site): array => [
                'label' => $site->name . ' (' . $site->handle . ')',
                'value' => (string)$site->id,
            ],
            Craft::$app->getSites()->getAllSites()
        );
    }

    /**
     * Returns section options used by Entry condition filters.
     *
     * @return array Selectable section labels and handles.
     */
    private function sectionOptions(): array
    {
        $entries = Craft::$app->getEntries();
        if (!method_exists($entries, 'getAllSections')) {
            return [];
        }

        return array_map(
            static fn($section): array => [
                'label' => $section->name,
                'value' => $section->handle,
            ],
            $entries->getAllSections()
        );
    }

    /**
     * Returns entry type options used by Entry condition filters.
     *
     * @return array Selectable entry type labels and handles.
     */
    private function entryTypeOptions(): array
    {
        $entries = Craft::$app->getEntries();
        if (!method_exists($entries, 'getAllEntryTypes')) {
            return [];
        }

        return array_map(
            static fn($type): array => [
                'label' => $type->name,
                'value' => $type->handle,
            ],
            $entries->getAllEntryTypes()
        );
    }

    /**
     * Removes duplicate condition field definitions while preserving first-match ordering.
     *
     * @param array $fields Condition field definitions.
     * @return array Unique condition field definitions.
     */
    private function uniqueConditionFields(array $fields): array
    {
        $unique = [];
        foreach ($fields as $field) {
            $value = (string)($field['value'] ?? '');
            if ($value === '' || isset($unique[$value])) {
                continue;
            }

            $unique[$value] = $field;
        }

        return array_values($unique);
    }

    /**
     * Converts a handle or camelCase property name into a readable condition label segment.
     *
     * @param string $handle Handle or property name to format.
     * @return string Human-readable label text.
     */
    private function titleFromHandle(string $handle): string
    {
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', $handle) ?: $handle;
        $words = str_replace(['_', '-'], ' ', $words);

        return ucwords(trim($words));
    }

    /**
     * Extracts a human-readable property description from a PHPDoc block.
     *
     * @param string $docblock docblock value used by this method.
     * @return string Return value produced by this method.
     */
    private function propertyDescription(string $docblock): string
    {
        if (preg_match('/@var\s+[^\s]+\s+(.+)/', $docblock, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * Extracts a human-readable method return description from a PHPDoc block.
     *
     * @param string $docblock docblock value used by this method.
     * @return string Return value produced by this method.
     */
    private function methodDescription(string $docblock): string
    {
        if (preg_match('/@return\s+[^\s]+\s+(.+)/', $docblock, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * Checks whether a variable entry already exists before adding duplicate event variable metadata.
     *
     * @param array $variables variables value used by this method.
     * @param string $name name value used by this method.
     * @return bool Return value produced by this method.
     */
    private function hasVariable(array $variables, string $name): bool
    {
        foreach ($variables as $variable) {
            if (($variable['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates copyable PHP listener code for the selected event.
     *
     * @param string $class class value used by this method.
     * @param string $constant constant value used by this method.
     * @param string $eventType eventType value used by this method.
     * @return string Return value produced by this method.
     */
    private function exampleCode(string $class, string $constant, string $eventType): string
    {
        $classShort = $this->shortClassName($class);
        $eventShort = $this->shortClassName($eventType);

        return implode("\n", [
            'use yii\\base\\Event;',
            'use ' . $class . ';',
            'use ' . $eventType . ';',
            '',
            'Event::on(',
            '    ' . $classShort . '::class,',
            '    ' . $classShort . '::' . $constant . ',',
            '    function (' . $eventShort . ' $event) {',
            '        // ...',
            '    }',
            ');',
        ]);
    }

    /**
     * Returns the final class segment for display in generated code.
     *
     * @param string $class class value used by this method.
     * @return string Return value produced by this method.
     */
    private function shortClassName(string $class): string
    {
        return substr($class, (int)strrpos($class, '\\') + 1);
    }

    /**
     * Builds the human-readable event label shown in the picker.
     *
     * @param string $class class value used by this method.
     * @param string $constant constant value used by this method.
     * @param string $eventName eventName value used by this method.
     * @return string Return value produced by this method.
     */
    private function labelFor(string $class, string $constant, string $eventName): string
    {
        return $class . '::' . $constant . ' (' . $eventName . ')';
    }
}
