<?php
namespace amici\SuperMailer\services;

use Craft;
use craft\base\Element;
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
