# Schema Builder

This module describes a content entity bundle as a JSON Schema. It walks the fields of the bundle and fires an event
at each level, and the builders subscribed to those events write the keywords. The module writes only the object that
holds the fields of the bundle. Every other keyword comes from a builder, so what a schema says depends on which
builders are installed.

The schema describes **denormalization input**. It states what a payload must look like for
`$serializer->deserialize()` to turn it back into an entity. It does not describe what the entity serializes to, and
the two shapes differ.

## Calling it

The service builds the schema of one bundle.

```php
$schema = $this->schemaBuilder->get('node', 'oe_news');
```

The service is `Drupal\schema_builder\SchemaBuilderInterface`. A third argument reaches every builder untouched,
including the builders of a bundle a reference walks into:

```php
$schema = $this->schemaBuilder->get('node', 'oe_news', ['template' => $index]);
```

The same schema can be printed from the command line:

```bash
drush schema-builder:dump oe_news
drush schema-builder:dump hero --entity-type=paragraph
drush schema-builder:dump oe_news --out=/tmp/oe_news.json
```

## What comes out

A required, single-valued string field with a maximum length comes out like this:

```json
"title": {
  "type": "array",
  "items": {
    "type": "object",
    "properties": {"value": {"type": "string", "maxLength": 255}},
    "additionalProperties": false,
    "required": ["value"]
  },
  "maxItems": 1,
  "minItems": 1,
  "description": "Title"
}
```

Every field is an array, whatever its cardinality, because that is the shape the denormalizer reads back. A field
holds items, and an item holds the columns of the field type.

An `entity_reference_revisions` field describes whole entities instead of a pointer to one. Each variant names its
own bundle, so a reader can tell which one a payload is meant to be:

```json
"field_content_paragraphs": {
  "type": "array",
  "items": {
    "type": "object",
    "oneOf": [
      {"type": "object", "properties": {"type": {"...": "const text_block"}, "field_text_body": {"...": "..."}}},
      {"type": "object", "properties": {"type": {"...": "const quote_block"}, "field_quote_text": {"...": "..."}}}
    ]
  }
}
```

## The three levels

`SchemaBuilder` assembles the nesting and fires one event per level.

| Level | Event | Fired | Carries |
|----------|-------------------------|-----------------|-------------------------------------------------|
| Field | `FieldSchemaEvent` | once per field | the field definition, the `items` already built |
| Item | `FieldItemSchemaEvent` | once per field | the field definition, the `properties` built |
| Property | `PropertySchemaEvent` | once per column | the property definition, its constraints |

A property event starts from an empty array, so even `type` comes from a builder.

The field and item events both describe one field, and they differ in what they are handed. A field builder sees the
array wrapper, so it can set `maxItems` or drop the field. An item builder sees all the columns of the field at once,
which is what it takes to find one column among its siblings.

## Writing a builder

A builder is a class with an attribute saying which fields it runs on, and one method.

```php
namespace Drupal\my_module\SchemaBuilder;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\Context\PropertyContext;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;

/**
 * Describes the uri of a link field as a URI.
 */
#[PropertySchemaBuilder(
  field_type: 'link',
  property_name: 'uri',
  weight: 10,
)]
final class LinkUriBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $schema['format'] = 'uri';

    return $schema;
  }

}
```

Pick the base class for the level you want: `FieldSchemaBuilderBase`, `FieldItemSchemaBuilderBase` or
`PropertySchemaBuilderBase`. Each one hands `build()` the context type of its level, so `build()` needs no check.

Builders are autowired, so dependencies go in the constructor.

### Registering it

Put the class under `src/SchemaBuilder/` of your module and give it one line in your `services.yml`:

```yaml
services:
  _defaults:
    autoconfigure: true
    autowire: true

  Drupal\my_module\SchemaBuilder\LinkUriBuilder: {}
```

Every builder base class implements `EventSubscriberInterface`, and core registers an autoconfiguration rule for it.
`autoconfigure: true` is therefore what tags the service and subscribes the builder. Without that line the builder is
never called and nothing reports it, so check it first when a builder appears to do nothing.

Scanning `src/SchemaBuilder/` would load every class in it while the container compiles, and a class whose parent
lives in an uninstalled module is a fatal error there rather than something to skip. So there is no directory
scanning. A class with no service entry is not a service, so no autoconfiguration rule reaches it, and each builder
needs its own line.

### Matching

A builder runs when every property its attribute declares matches, and a property left out matches anything. A
builder declaring only a field type therefore runs on that field type wherever it appears.

| Property | Matches |
|-----------------|-----------------------------------------------------------------|
| `entity_type` | the entity type being described |
| `bundle` | the bundle being described |
| `field_name` | the machine name of the field |
| `field_type` | the field type, one or a list |
| `constraint` | the level carries one of these validation constraints |
| `property_name` | the column name, property level only |
| `data_type` | the typed-data type of the column, property level only |

`data_type` reaches further than `field_type`. Matching `filter_format` covers every field type that stores formatted
text, contrib ones included, which a list of field types would miss.

A builder declaring a `constraint` only runs when the level carries it, so it needs no check of its own:

```php
#[PropertySchemaBuilder(
  constraint: 'Length',
)]
```

### Weight

Builders run from the lightest to the heaviest, so the heaviest has the last word on a keyword two of them set. Give
a builder matching one bundle a heavier weight than one matching every field.

Weight is a float, scaled to the integer priority the dispatcher takes. Three decimal places survive the scaling,
which is room to slot a builder between two others without renumbering them. Those priorities share no scale with
anything else, so only builders may subscribe to these events.

The builders this module ships sit between -100 and 50. A module describing its own content type needs a weight above
that.

### Dropping a field

A field builder may return NULL instead of a schema, which leaves the field out of the bundle altogether. That is how
a module decides which fields are worth asking a model about.

Nothing takes that decision back. Once a builder returns NULL the field is gone, whatever a heavier builder does.
Two builders that might disagree should read the same source and agree, rather than take turns.

## What ships here

The module ships builders at each of the three levels.

Field level:

- `FieldStructureBuilder` wraps the field as an array and sets `maxItems` and `minItems` from cardinality.
- `FieldDescriptionBuilder` sets `description` from the field description, falling back to the label.
- `BundleKeyBuilder` pins the bundle field to the bundle being described.

Item level:

- `InternalPropertyBuilder` drops the columns nothing may write to: computed, internal and serialized.
- `ItemStructureBuilder` closes the item and lists its required columns.
- `ReferenceBuilder` walks an `entity_reference_revisions` field into each bundle it accepts.

Property level:

- `DataTypeBuilder` sets `type` from the typed-data type.
- `LengthBuilder`, `RangeBuilder`, `CountBuilder`, `ChoiceBuilder`, `PatternBuilder`, `StringFormatBuilder` and
  `ConstantBuilder` translate the validation constraints core ships.
- `FieldSettingsBuilder` reads what the settings of one field say about its own value.
- `TextFormatBuilder` lists the text formats the current user may write in.

Naming field types one by one would cover only the ones named. These builders read the constraints instead, so a field
type that declares a `Length` gets a `maxLength` with no code written for it, core or contrib alike.

## Where the constraints come from

Drupal states the limits of a value in three places, and all three reach a builder through the context.

- The field settings, such as `max_length` or `allowed_values`.
- The field item, as a `ComplexData` constraint naming one of its columns. `StringItem` and `NumericItemBase` both
  do this, and none of it appears on the property definitions. `SchemaBuilder` builds an item to read it, which needs
  no entity and no stored values.
- The property definition itself, such as `Email` on the `email` data type.

## Things to know

A schema can depend on the account, because `TextFormatBuilder` lists the formats the current user may write in. This
module caches nothing, so a caller that caches a schema needs the `user.permissions` cache context.

Asking for a bundle that is already being walked returns an empty array, which the builder that asked drops. That is
what stops a bundle reaching itself from recursing without end.

The schema holds nothing outside the JSON Schema specification. A provider that enforces the schema it is given
rejects a keyword it does not know, so a builder needing something about the field reads it off the field definition
rather than leaving a note in the output.
