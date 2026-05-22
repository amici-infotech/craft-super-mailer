<?php
namespace amici\SuperMailer\models;

use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use ArrayAccess;
use JsonSerializable;

/**
 * Template-facing wrapper around normalized event context that exposes array-style, property-style, and getter-style access.
 */
class EventContext implements ArrayAccess, JsonSerializable
{
    private array $rawData;

    /**
     * Initializes the render context with normalized event data and the optional live Craft element.
     *
     * The raw array is kept untouched for JSON output and the preview's raw context panel, while the
     * mutable data array gains convenient aliases such as `element`, `sender`, `submission`, and `form`.
     *
     * @param array $data Normalized event context produced by the notification service.
     * @param Element|null $element Rehydrated primary element, when one can be loaded.
     */
    public function __construct(
        private array $data,
        private ?Element $element = null
    ) {
        $this->rawData = $data;

        if ($this->element) {
            $this->data['element'] = $this->element;
            $this->data['sender'] = $this->element;

            if ($this->getSubmission()) {
                $this->data['submission'] = $this->element;
            }

            if ($this->getForm()) {
                $this->data['form'] = $this->getForm();
            }
        }
    }

    /**
     * Provides property-style access to normalized event context data.
     *
     * @param string $name name value used by this method.
     * @return mixed Return value produced by this method.
     */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        if (is_array($this->data['data'] ?? null) && array_key_exists($name, $this->data['data'])) {
            return $this->data['data'][$name];
        }

        return null;
    }

    /**
     * Reports whether a normalized event context value exists.
     *
     * @param string $name name value used by this method.
     * @return bool Return value produced by this method.
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data)
            || (is_array($this->data['data'] ?? null) && array_key_exists($name, $this->data['data']));
    }

    /**
     * Provides getter-style access to normalized event context values.
     *
     * @param string $name name value used by this method.
     * @param array $arguments arguments value used by this method.
     * @return mixed Return value produced by this method.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'get')) {
            $property = lcfirst(substr($name, 3));
            if ($property !== '' && $this->__isset($property)) {
                return $this->__get($property);
            }
        }

        return null;
    }

    /**
     * Reports whether an array-access offset exists on the event context.
     *
     * @param mixed $offset offset value used by this method.
     * @return bool Return value produced by this method.
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->__isset($offset);
    }

    /**
     * Returns an array-access value from the event context.
     *
     * @param mixed $offset offset value used by this method.
     * @return mixed Return value produced by this method.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->__get($offset) : null;
    }

    /**
     * Sets an array-access value on the mutable render context.
     *
     * @param mixed $offset offset value used by this method.
     * @param mixed $value value value used by this method.
     * @return void Return value produced by this method.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset)) {
            $this->data[$offset] = $value;
        }
    }

    /**
     * Removes an array-access value from the mutable render context.
     *
     * @param mixed $offset offset value used by this method.
     * @return void Return value produced by this method.
     */
    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            unset($this->data[$offset]);
        }
    }

    /**
     * Returns the rehydrated primary element for this context when available.
     *
     * @return ?Element Return value produced by this method.
     */
    public function getElement(): ?Element
    {
        return $this->element;
    }

    /**
     * Returns the primary element when it is an Entry.
     *
     * @return ?Entry Return value produced by this method.
     */
    public function getEntry(): ?Entry
    {
        return $this->element instanceof Entry ? $this->element : null;
    }

    /**
     * Returns the primary element when it is an Asset.
     *
     * @return ?Asset Return value produced by this method.
     */
    public function getAsset(): ?Asset
    {
        return $this->element instanceof Asset ? $this->element : null;
    }

    /**
     * Returns the primary element when it is a Category.
     *
     * @return ?Category Return value produced by this method.
     */
    public function getCategory(): ?Category
    {
        return $this->element instanceof Category ? $this->element : null;
    }

    /**
     * Returns the primary element when it is a User.
     *
     * @return ?User Return value produced by this method.
     */
    public function getUser(): ?User
    {
        return $this->element instanceof User ? $this->element : null;
    }

    /**
     * Returns the primary element when it represents a submission element.
     *
     * @return ?Element Return value produced by this method.
     */
    public function getSubmission(): ?Element
    {
        return $this->element && str_ends_with($this->element::class, '\\Submission') ? $this->element : null;
    }

    /**
     * Returns a related form object when the primary element exposes one.
     *
     * @return mixed Return value produced by this method.
     */
    public function getForm(): mixed
    {
        if (!$this->element || !method_exists($this->element, 'getForm')) {
            return null;
        }

        return $this->element->getForm();
    }

    /**
     * Returns the raw serialized context for JSON encoding.
     *
     * @return array Return value produced by this method.
     */
    public function jsonSerialize(): array
    {
        return $this->rawData;
    }

    /**
     * Builds a concise map of template-facing variables for the preview page.
     *
     * @return array Return value produced by this method.
     */
    public function previewVariables(): array
    {
        return [
            'event.eventClass' => $this->data['eventClass'] ?? null,
            'event.eventName' => $this->data['eventName'] ?? null,
            'event.isNew' => $this->data['isNew'] ?? null,
            'event.element' => $this->describeValue($this->data['element'] ?? null),
            'event.sender' => $this->describeValue($this->data['sender'] ?? null),
            'event.submission' => $this->describeValue($this->data['submission'] ?? null),
            'event.form' => $this->describeValue($this->data['form'] ?? null),
            'event.getElement()' => $this->describeValue($this->getElement()),
            'event.getSubmission()' => $this->describeValue($this->getSubmission()),
            'event.getForm()' => $this->describeValue($this->getForm()),
            'event.data' => $this->data['data'] ?? null,
        ];
    }

    /**
     * Converts objects and elements into readable preview metadata.
     *
     * @param mixed $value value value used by this method.
     * @return mixed Return value produced by this method.
     */
    private function describeValue(mixed $value): mixed
    {
        if ($value instanceof Element) {
            return [
                'class' => $value::class,
                'id' => $value->id,
                'title' => (string)$value,
            ];
        }

        if (is_object($value)) {
            $data = [
                'class' => $value::class,
            ];

            foreach (['id', 'handle', 'name', 'title'] as $property) {
                try {
                    if (isset($value->{$property})) {
                        $data[$property] = $value->{$property};
                    }
                } catch (\Throwable) {
                }
            }

            return $data;
        }

        return $value;
    }
}
