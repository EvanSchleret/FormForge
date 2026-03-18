<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Definition;

use EvanSchleret\FormForge\Exceptions\InvalidFieldDefinitionException;
use EvanSchleret\FormForge\Support\Schema;

class FormBlueprint
{
    private string $key;

    private ?string $title = null;

    private ?string $version = null;

    private array $fields = [];

    private array $api = [];

    private ?string $category = null;

    private ?bool $published = null;

    public function __construct(string $key)
    {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidFieldDefinitionException('Form key cannot be empty.');
        }

        $this->key = $key;
    }

    public static function fromSchemaArray(array $schema): self
    {
        $key = (string) ($schema['key'] ?? '');
        $form = new self($key);

        if (isset($schema['title'])) {
            $form->title((string) $schema['title']);
        }

        if (isset($schema['version'])) {
            $form->version((string) $schema['version']);
        }

        if (isset($schema['category'])) {
            $form->category((string) $schema['category']);
        }

        if (array_key_exists('is_published', $schema)) {
            $form->published((bool) $schema['is_published']);
        }

        if (isset($schema['api']) && is_array($schema['api'])) {
            $form->api = ApiBlueprint::fromArray($schema['api'])->toArray();
        }

        $fields = $schema['fields'] ?? [];

        if (! is_array($fields)) {
            throw new InvalidFieldDefinitionException("Invalid fields definition for form [{$key}].");
        }

        foreach ($fields as $fieldSchema) {
            if (! is_array($fieldSchema)) {
                throw new InvalidFieldDefinitionException("Invalid field schema in form [{$key}].");
            }

            $field = FieldBlueprint::fromSchemaArray($fieldSchema);

            if ($form->findField($field->name()) !== null) {
                throw new InvalidFieldDefinitionException("Duplicate field name [{$field->name()}] in form [{$key}].");
            }

            $form->fields[] = $field->attachToForm($form);
        }

        return $form;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(string $title): self
    {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidFieldDefinitionException('Form title cannot be empty.');
        }

        $this->title = $title;

        return $this;
    }

    public function version(string $version): self
    {
        $version = trim($version);

        if ($version === '') {
            throw new InvalidFieldDefinitionException('Form version cannot be empty.');
        }

        $this->version = $version;

        return $this;
    }

    public function category(string $category): self
    {
        $category = trim($category);

        if ($category === '') {
            throw new InvalidFieldDefinitionException('Form category cannot be empty.');
        }

        $this->category = $category;

        return $this;
    }

    public function published(bool $published = true): self
    {
        $this->published = $published;

        return $this;
    }

    public function unpublished(bool $unpublished = true): self
    {
        $this->published = ! $unpublished;

        return $this;
    }

    public function text(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::TEXT, $name);
    }

    public function textarea(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::TEXTAREA, $name);
    }

    public function email(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::EMAIL, $name);
    }

    public function number(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::NUMBER, $name);
    }

    public function select(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::SELECT, $name);
    }

    public function selectMenu(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::SELECT_MENU, $name);
    }

    public function radio(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::RADIO, $name);
    }

    public function checkbox(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::CHECKBOX, $name);
    }

    public function checkboxGroup(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::CHECKBOX_GROUP, $name);
    }

    public function switch(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::SWITCH, $name);
    }

    public function date(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::DATE, $name);
    }

    public function time(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::TIME, $name);
    }

    public function datetime(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::DATETIME, $name);
    }

    public function dateRange(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::DATE_RANGE, $name);
    }

    public function datetimeRange(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::DATETIME_RANGE, $name);
    }

    public function file(string $name): FieldBlueprint
    {
        return $this->addField(FieldType::FILE, $name);
    }

    public function api(callable|array $config): self
    {
        if (is_array($config)) {
            $this->api = ApiBlueprint::fromArray($this->mergeArrays($this->api, $config))->toArray();

            return $this;
        }

        $blueprint = ApiBlueprint::fromArray($this->api);
        $result = $config($blueprint);

        if ($result instanceof ApiBlueprint) {
            $blueprint = $result;
        }

        $this->api = $blueprint->toArray();

        return $this;
    }

    public function addField(string $type, string $name): FieldBlueprint
    {
        FieldType::assert($type);

        $name = trim($name);

        if ($name === '') {
            throw new InvalidFieldDefinitionException('Field name cannot be empty.');
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidFieldDefinitionException("Invalid field name [{$name}].");
        }

        if ($this->findField($name) !== null) {
            throw new InvalidFieldDefinitionException("Duplicate field name [{$name}] in form [{$this->key}].");
        }

        $field = new FieldBlueprint($this, $type, $name);
        $this->fields[] = $field;

        return $field;
    }

    public function versionValue(): ?string
    {
        return $this->version;
    }

    public function titleValue(): string
    {
        return $this->title ?? $this->key;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function apiValue(): array
    {
        return $this->api;
    }

    public function categoryValue(): string
    {
        $defaultCategory = trim((string) config('formforge.forms.default_category', 'general'));

        if ($this->category !== null) {
            return $this->category;
        }

        return $defaultCategory === '' ? 'general' : $defaultCategory;
    }

    public function isPublished(): bool
    {
        if ($this->published !== null) {
            return $this->published;
        }

        return (bool) config('formforge.forms.default_published', true);
    }

    public function findField(string $name): ?FieldBlueprint
    {
        foreach ($this->fields as $field) {
            if ($field->name() === $name) {
                return $field;
            }
        }

        return null;
    }

    public function toSchemaArray(): array
    {
        if ($this->version === null) {
            throw new InvalidFieldDefinitionException("Form [{$this->key}] must define a version.");
        }

        $fields = [];
        $fieldKeys = [];

        foreach ($this->fields as $field) {
            $schema = $field->toSchemaArray($this->key, $this->version);
            $fieldKey = (string) ($schema['field_key'] ?? '');

            if ($fieldKey === '') {
                throw new InvalidFieldDefinitionException("Field [{$field->name()}] generated an empty field_key.");
            }

            if (in_array($fieldKey, $fieldKeys, true)) {
                throw new InvalidFieldDefinitionException("Duplicate field_key [{$fieldKey}] in form [{$this->key}] version [{$this->version}].");
            }

            $fieldKeys[] = $fieldKey;
            $fields[] = $schema;
        }

        $schema = [
            'key' => $this->key,
            'version' => $this->version,
            'title' => $this->titleValue(),
            'fields' => $fields,
        ];

        if ($this->category !== null) {
            $schema['category'] = $this->category;
        }

        if ($this->published !== null) {
            $schema['is_published'] = $this->published;
        }

        if ($this->api !== []) {
            $schema['api'] = $this->api;
        }

        return $schema;
    }

    public function schemaHash(): string
    {
        return Schema::hash($this->toSchemaArray());
    }

    private function mergeArrays(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = $this->mergeArrays($left[$key], $value);
                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }
}
