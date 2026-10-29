# Stable Property IDs

Work in progress, not published to the site. Draft of a discussion post replying to
[ADR 38](../adr/038-property-names.md), whose consequence reads: "Renaming a Property still breaks
references to it (ADR 17), even when only case or underscores change."

Discussion: [#NNNN](https://github.com/ProfessionalWiki/NeoWiki/discussions/NNNN) — fill in once the
discussion is created.

## Why this is a problem, not just a consequence

For references written as text, a rename breaking them is annoying. For stored data it is
corruption: the data stays present but stops matching the Schema, and anyone can trigger it at any
time in the Schema editor. Issue [#1802](https://github.com/ProfessionalWiki/NeoWiki/issues/1802)
shows it concretely: renaming "Attendance figure" to "Annual attendance" in Schema:Museum left the
stored statements and the `neowiki_relation` rows under the old name, so the Annual attendance
sub-subjects disappeared from the Rijksmuseum subject box until the rename was reverted.

An alias map (old name → new name) is not a sound fix: over a Schema's edit history, different
properties can have shared the same name at different times, so a name alone cannot unambiguously
identify a property.

## Proposal: stable property ids

Merged from the ADR 039 draft and its clarifications.

1. Every property definition in a Schema carries a **stable, unique id** (32 hex chars, generated
   when the property is created). The property **name is a label** and may be renamed freely.
2. Schema JSON — each property definition gains an optional `id`:

   ```json
   {
     "propertyDefinitions": {
       "Annual attendance": {
         "id": "a1b2c3d4e5f6...",
         "type": "relation",
         "targetSchemas": ["Annual attendance"]
       }
     }
   }
   ```

3. Subject JSON — each statement gains an optional `propertyId` next to its name key:

   ```json
   {
     "statements": {
       "Annual attendance": {
         "propertyId": "a1b2c3d4e5f6...",
         "propertyType": "relation",
         "value": []
       }
     }
   }
   ```

4. The relation index `neowiki_relation` gains a `nwr_property_id` column next to `nwr_property`.
   `nwr_property` keeps the current name and is rewritten with it on every rebuild (every page
   save); lookups that resolve a row to a Schema property use id first, name as fallback — the
   same order as statements.
5. Lookup order when resolving a statement or relation row to a Schema property:
   1. If it has a `propertyId` and the Schema has a property with that id → use it.
   2. Otherwise, fall back to the property name.

   A rename is then transparent: the id is unchanged, so the data still resolves.
6. Migration: Schemas saved before this change get an id generated for every property on their
   next save. Statements and relation rows written before this change have no id, resolve by name,
   and get the current id attached when their page is next saved.
7. Scope: the id stabilizes **stored data only** — statements and relation-index rows. References
   written as text (`{{#neowiki_value}}`, Lua, Layouts, Mappings, RDF predicates, graph relationship
   types) still go by name ([ADR 38](../adr/038-property-names.md)) and still break on a rename.
8. Deleting a property from a Schema never deletes statements. A statement whose `propertyId` and
   name match no property is kept verbatim and shown as unmatched.
9. Copying a property definition within a Schema gives the copy a new id. Ids are unique per
   Schema, not globally; they travel with the Schema JSON through imports and Sources and cannot
   collide, for the same reason.

## Open questions

- How should textual references become rename-safe long-term? Ids are per-Schema and opaque, and
  wikitext, Lua and Mappings are written by hand.
- Should the Schema editor warn on a rename about textual references that will break (where-used,
  [#1039](https://github.com/ProfessionalWiki/NeoWiki/issues/1039))?
- Should `nwr_property` (the name column) eventually be dropped from `neowiki_relation`?

## References

- [ADR 38: Property Names](../adr/038-property-names.md) — the consequence this replies to
- [ADR 17: Names as Identifiers](../adr/017-names-as-identifiers.md)
- [ADR 39 draft](../adr/039-stable-property-ids.md)
- [ADR 37: Subject Lookups in MediaWiki Tables](../adr/037-subject-lookups-in-mediawiki-tables.md) — the four features reading `neowiki_relation`
- [ADR 35: Dependent Subjects](../adr/035-dependent-subjects.md) — the sub-subjects of #1802
- [#1802](https://github.com/ProfessionalWiki/NeoWiki/issues/1802) — sub-subjects lost after renaming a property
