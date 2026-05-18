<?php
namespace amici\SuperMailer\services;

use Craft;
use craft\base\Element;
use craft\services\Elements;
use ReflectionClass;
use Throwable;
use yii\base\Component;

class EventRegistryService extends Component
{
    private ?array $_events = null;

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

    public function encodeEventValue(string $class, string $eventName, string $constant): string
    {
        return base64_encode(json_encode([
            'class' => $class,
            'constant' => $constant,
            'eventName' => $eventName,
        ]));
    }

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

    public function isValidEvent(string $class, string $eventName): bool
    {
        foreach ($this->getEvents() as $event) {
            if ($event['class'] === $class && $event['eventName'] === $eventName) {
                return true;
            }
        }

        return false;
    }

    private function discoverEventDefinitions(): array
    {
        $definitions = [];

        foreach ($this->registeredContentEventClasses() as $class) {
            foreach ($this->reflectedEventDefinitions($class) as $definition) {
                $definitions[$definition['class'] . '::' . $definition['eventName']] = $definition;
            }
        }

        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'craft\\') || str_starts_with($class, 'yii\\')) {
                foreach ($this->reflectedEventDefinitions($class) as $definition) {
                    $definitions[$definition['class'] . '::' . $definition['eventName']] = $definition;
                }
            }
        }

        foreach ($this->scanRoots() as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach ($this->scanPhpFiles($root) as $file) {
                $class = $this->classNameFromFile($file);
                if ($class !== null) {
                    if (str_starts_with($class, 'craft\\') || str_starts_with($class, 'yii\\')) {
                        foreach ($this->reflectedEventDefinitions($class) as $definition) {
                            $definitions[$definition['class'] . '::' . $definition['eventName']] = $definition;
                        }
                        continue;
                    }

                    foreach ($this->eventConstantsFromFile($file) as $constant => $eventDefinition) {
                        $eventName = $eventDefinition['eventName'];
                        $definitions[$class . '::' . $eventName] = [
                            'class' => $class,
                            'constant' => $constant,
                            'eventName' => $eventDefinition['eventName'],
                            'eventType' => $eventDefinition['eventType'],
                        ];
                    }
                }
            }
        }

        ksort($definitions);

        return array_values($definitions);
    }

    private function isContentManagementEvent(string $class, string $constant, string $eventName): bool
    {
        if (str_starts_with($constant, 'EVENT_BEFORE_')) {
            return false;
        }

        if (preg_match('/EVENT_(REGISTER|DEFINE|MODIFY|RENDER|VALIDATE|AUTHORIZE|SET_|PREP_|CONFIGURE|COMPILE|TRANSFORM|DEFAULT)_/', $constant)) {
            return false;
        }

        if ($class === Elements::class) {
            return in_array($constant, [
                'EVENT_AFTER_SAVE_ELEMENT',
                'EVENT_AFTER_DELETE_ELEMENT',
                'EVENT_AFTER_RESTORE_ELEMENT',
                'EVENT_AFTER_PROPAGATE_ELEMENT',
                'EVENT_AFTER_DELETE_FOR_SITE',
            ], true);
        }

        if (in_array($class, $this->registeredContentEventClasses(), true)) {
            return $this->isContentLifecycleConstant($constant, $eventName);
        }

        if (str_starts_with($class, 'yii\\')) {
            return false;
        }

        if (str_starts_with($class, 'craft\\') && !str_starts_with($class, 'craft\\commerce\\')) {
            return false;
        }

        return $this->isPluginContentEvent($constant, $eventName);
    }

    private function isContentLifecycleConstant(string $constant, string $eventName): bool
    {
        if (in_array($constant, [
            'EVENT_AFTER_SAVE',
            'EVENT_AFTER_DELETE',
            'EVENT_AFTER_RESTORE',
            'EVENT_AFTER_PROPAGATE',
            'EVENT_AFTER_MOVE_IN_STRUCTURE',
            'EVENT_AFTER_DELETE_FOR_SITE',
            'EVENT_AFTER_SUBMIT',
            'EVENT_AFTER_SUBMISSION',
            'EVENT_PROCESS_SUBMISSION',
            'EVENT_AFTER_ORDER_PAID',
            'EVENT_AFTER_ORDER_AUTHORIZED',
            'EVENT_AFTER_ADD_LINE_ITEM',
            'EVENT_AFTER_REMOVE_LINE_ITEM',
        ], true)) {
            return true;
        }

        return str_starts_with($constant, 'EVENT_AFTER_')
            && !preg_match('/(VALIDATE|RENDER|HTML|TABLE|FIELD|LABEL|INPUT|INSTRUCTION|CACHE|SEARCH|INDEX|QUERY|EXPORT|EMAIL|MAIL|LAYOUT)$/', $constant)
            && !preg_match('/(validate|render|html|table|field|label|input|instruction|cache|search|index|query|export|email|mail|layout)/', $eventName);
    }

    private function isPluginContentEvent(string $constant, string $eventName): bool
    {
        if (preg_match('/(VALIDATE|RENDER|HTML|TABLE|FIELD|LABEL|INPUT|INSTRUCTION|CACHE|SEARCH|INDEX|QUERY|EXPORT|LAYOUT)/', $constant)
            || preg_match('/(validate|render|html|table|field|label|input|instruction|cache|search|index|query|export|layout)/', $eventName)) {
            return false;
        }

        $contentKeywords = [
            'SAVE',
            'DELETE',
            'SUBMIT',
            'SUBMISSION',
            'UPLOAD',
            'PAYMENT',
            'ORDER',
            'TRANSACTION',
            'STATUS_CHANGE',
            'CREATE',
            'COMPLETE',
            'PROCESS',
        ];

        $eventText = strtoupper($constant . ' ' . $eventName);
        foreach ($contentKeywords as $keyword) {
            if (str_contains($eventText, $keyword)) {
                return str_starts_with($constant, 'EVENT_AFTER_')
                    || str_contains($constant, 'STATUS_CHANGE')
                    || str_contains($constant, 'SUBMIT')
                    || str_contains($constant, 'SUBMISSION');
            }
        }

        return false;
    }

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

        return $classes = array_values(array_unique($classes));
    }

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
                    'eventType' => $this->eventTypeFromReflectionConstant($constant),
                ];
            }

            return $definitions;
        } catch (Throwable) {
            return [];
        }
    }

    private function scanRoots(): array
    {
        $roots = [];

        try {
            $roots[] = Craft::getAlias('@craft');
        } catch (Throwable) {
        }

        try {
            $yiiReflection = new ReflectionClass(\yii\base\Event::class);
            $roots[] = dirname((string)$yiiReflection->getFileName(), 2);
        } catch (Throwable) {
        }

        try {
            foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
                if (method_exists($plugin, 'getBasePath')) {
                    $roots[] = $plugin->getBasePath();
                }
            }
        } catch (Throwable) {
        }

        return array_values(array_unique(array_filter($roots)));
    }

    private function scanPhpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $files[] = $path;
        }

        return $files;
    }

    private function classNameFromFile(string $file): ?string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $namespace = '';
        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = $tokens[$j];
                    if ($part === ';' || $part === '{') {
                        break;
                    }
                    if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $part[1];
                    }
                }
            }

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE], true)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = $tokens[$j];
                    if (is_array($part) && $part[0] === T_STRING) {
                        return ltrim($namespace . '\\' . $part[1], '\\');
                    }
                }
            }
        }

        return null;
    }

    private function eventConstantsFromFile(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $namespace = $this->namespaceFromContents($contents);
        $uses = $this->usesFromContents($contents);
        preg_match_all(
            '/(?:(\/\*\*.*?\*\/)\s*)?(?:public|protected|private)?\s*const\s+(EVENT_[A-Z0-9_]+)\s*=\s*([\'"])(.*?)\3\s*;/s',
            $contents,
            $matches,
            PREG_SET_ORDER
        );

        $constants = [];
        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '' && ($match[4] ?? '') !== '') {
                $eventType = $this->eventTypeFromDocblock($match[1] ?? '', $namespace, $uses);
                $constants[$match[2]] = [
                    'eventName' => stripcslashes($match[4]),
                    'eventType' => $eventType,
                ];
            }
        }

        return $constants;
    }

    private function namespaceFromContents(string $contents): string
    {
        if (preg_match('/^\s*namespace\s+([^;{]+)[;{]/m', $contents, $match)) {
            return trim($match[1]);
        }

        return '';
    }

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

    private function eventTypeFromDocblock(string $docblock, string $namespace, array $uses): string
    {
        if (preg_match('/@event\s+([\\\\a-zA-Z0-9_]+)/', $docblock, $match)) {
            return $this->resolveClassName($match[1], $namespace, $uses);
        }

        return \yii\base\Event::class;
    }

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
        } catch (Throwable) {
        }

        usort($variables, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $variables;
    }

    private function propertyDescription(string $docblock): string
    {
        if (preg_match('/@var\s+[^\s]+\s+(.+)/', $docblock, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    private function hasVariable(array $variables, string $name): bool
    {
        foreach ($variables as $variable) {
            if (($variable['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

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

    private function shortClassName(string $class): string
    {
        return substr($class, (int)strrpos($class, '\\') + 1);
    }

    private function labelFor(string $class, string $constant, string $eventName): string
    {
        return $class . '::' . $constant . ' (' . $eventName . ')';
    }
}
