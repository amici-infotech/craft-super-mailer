<?php
namespace amici\SuperMailer\models;

use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use ArrayAccess;
use JsonSerializable;

class EventContext implements ArrayAccess, JsonSerializable
{
    private array $rawData;

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

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data)
            || (is_array($this->data['data'] ?? null) && array_key_exists($name, $this->data['data']));
    }

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

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->__isset($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->__get($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset)) {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            unset($this->data[$offset]);
        }
    }

    public function getElement(): ?Element
    {
        return $this->element;
    }

    public function getEntry(): ?Entry
    {
        return $this->element instanceof Entry ? $this->element : null;
    }

    public function getAsset(): ?Asset
    {
        return $this->element instanceof Asset ? $this->element : null;
    }

    public function getCategory(): ?Category
    {
        return $this->element instanceof Category ? $this->element : null;
    }

    public function getUser(): ?User
    {
        return $this->element instanceof User ? $this->element : null;
    }

    public function getSubmission(): ?Element
    {
        return $this->element && str_ends_with($this->element::class, '\\Submission') ? $this->element : null;
    }

    public function getForm(): mixed
    {
        if (!$this->element || !method_exists($this->element, 'getForm')) {
            return null;
        }

        return $this->element->getForm();
    }

    public function jsonSerialize(): array
    {
        return $this->rawData;
    }

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
