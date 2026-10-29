# ADR 039: Stable Property IDs

## Status

Draft

## Context

Schema properties were identified solely by their name (label). Statements stored the
property name as their key in the Subject JSON, and the relation index stored it in
`nwr_property`. When a user renamed a property in a Schema, the stored statements kept the
old name and could no longer be matched to the Schema's property — relations appeared
"lost" even though the data was still present (issue #1802).

A text-based alias map (old name → new name) is not a sound solution: over the edit history
of a Schema, different properties may have shared the same name at different times, so a
name alone cannot unambiguously identify a property.

## Decision

Every property definition in a Schema carries a **stable, unique id** (32 hex chars,
generated when the property is created). The property **name is a label** that may be
renamed freely. Statements store the property `id` alongside the name; readers resolve a
statement to its property by id first, falling back to name for data written before ids
existed.

### Format changes

Schema JSON — each property definition gains an optional `id`:

```json
{
  "propertyDefinitions": {
    "Annual attendance": {
      "id": "a1b2c3d4e5f6...",
      "type": "relation",
      "targetSchemas": ["Annual attendance"],
      ...
    }
  }
}
```

Subject JSON — each statement gains an optional `propertyId`:

```json
{
  "statements": {
    "Annual attendance": {
      "propertyId": "a1b2c3d4e5f6...",
      "propertyType": "relation",
      "value": [...]
    }
  }
}
```

### Migration

- Schemas saved before this change have no `id` on their properties. When such a Schema
  is next saved, the serializer generates an id for every property that lacks one.
- Statements written before this change have no `propertyId`. They are matched by name.
  When a Subject is next saved, the write path attaches the current property id from the
  Schema to each statement.

### Lookup order

When resolving a statement to a Schema property:

1. If the statement has a `propertyId` and the Schema has a property with that id → use it.
2. Otherwise, fall back to the property name.

This makes a rename transparent: the id is unchanged, so the statement still resolves.

## Consequences

- Renaming a property no longer orphans existing statements or relation-index rows.
- The relation index (`neowiki_relation.nwr_property`) continues to store the name; it is
  rebuilt from the Subject data on every page save, so it picks up the current name
  automatically.
- Property ids are unique per Schema, not globally. A statement's id is meaningful only
  in the context of its Subject's Schema.
