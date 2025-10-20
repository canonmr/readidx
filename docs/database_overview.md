# XBRL Database Structure

This document summarizes the relational model that backs the PHP importer in `scripts/extract_xbrl_to_mariadb.php`.  The goal is to
support thousands of IDX inline XBRL filings across hundreds of issuers and decades of quarters while keeping the schema predictable
for downstream analytics.

## Entity Relationship Overview

```
xbrl_documents (1) ───────────────┐
                                 │
                                 ▼
                        xbrl_taxonomy_concepts
                                 ▲
                                 │
xbrl_taxonomy_linkbases ─────────┘
                                 │
xbrl_taxonomy_role_refs ─────────┘

xbrl_documents (1) ───────────────┐
                                 │
                                 ▼
                            xbrl_contexts (1) ──────┬── xbrl_context_dimensions
                                 │                   │
                                 │                   └── context metadata JSON
                                 │
                                 └────── xbrl_units

xbrl_documents (1) ───────────────┐
                                 │
                                 ▼
                             xbrl_facts
```

* `xbrl_documents` anchors each imported archive.  Every other table ties back to this document identifier, which makes it easy to
  partition data per company, per quarter, or per import batch.
* Taxonomy tables store the static metadata (concept definitions and linkbases) that describe how a document organizes its facts.
* Context and unit tables capture the dimensional axes (entity, period, segment) that give meaning to numeric or textual facts.
* `xbrl_facts` contains the actual reported values and links each fact to both the document and its context.

## Table Details

### `xbrl_documents`

| Column          | Notes                                                                    |
|-----------------|--------------------------------------------------------------------------|
| `id`            | Surrogate key referenced throughout the schema.                          |
| `document_name` | Usually the ZIP filename, useful when browsing hundreds of filings.      |
| `document_hash` | SHA-256 of the XBRL instance. Prevents duplicate imports.                |
| `created_at`    | Timestamp of the import.                                                 |

Supporting index: `idx_xbrl_documents_name` accelerates lookups by human-readable names when browsing per issuer or period.

### `xbrl_taxonomy_concepts`
Stores one row per concept definition.  Concepts are de-duplicated by namespace + local name so that repeated imports of the same
taxonomy reuse the existing row and keep `id` stable.

### `xbrl_taxonomy_linkbases` and `xbrl_taxonomy_role_refs`
Capture presentation/calculation/definition references.  They are scoped per document so that different issuers or reporting
periods can reference distinct linkbases or role URIs.

### `xbrl_contexts`
Each context represents one `(entity, period, optional dimensions)` combination pulled from `<xbrli:context>`.  Columns capture:

* Entity identifier and scheme (issuer code + namespace published by IDX).
* Period attributes (`duration`, `instant`, or `forever`) and the relevant start/end dates.
* JSON blobs for segment/scenario to preserve dimensional detail without requiring dozens of join tables.

Indexes:

* `idx_xbrl_contexts_entity` speeds up filtering contexts by issuer identifier across thousands of documents.
* `idx_xbrl_contexts_period` helps answer queries like “give me Q1 2020 contexts for issuer X”.

### `xbrl_context_dimensions`
Optional normalized view of dimensional members.  Each row represents one explicit or typed member tied back to its parent context.
This makes it easy to pivot on dimensions (e.g., by business segment).

### `xbrl_units`
Stores the unit definitions referenced by facts (currencies, shares, ratios).  One row per unit per document keeps unit resolution
localized and enables reuse when the same document references a unit repeatedly.

### `xbrl_facts`
The fact table holds the reported values and ties them to both a taxonomy concept and a context.  Key attributes:

* `concept_id` links to `xbrl_taxonomy_concepts` when the concept definition is known.
* `context_id` and `unit_id` link to the contextual metadata.
* Separate storage for numeric and textual facts plus `decimals`/`precision` metadata.
* Indexes on `(document_id)` and `(concept_namespace, concept_local_name)` help slice facts per filing or concept family.
* `idx_xbrl_facts_context` accelerates lookups that start from a context (e.g., “list all facts for the Q1 2024 consolidated context”).

## Scaling for Hundreds of Issuers and Multi-Decade History

* **Per-document partitioning.** Every table uses `document_id` as a shard key, making it straightforward to archive or query per
  issuer or period.  With ~72,000 quarterly filings (700 issuers × 4 quarters × 26 years), each table’s indexes remain narrow and
  InnoDB can efficiently prune by `document_id`.
* **Deduplicated taxonomy concepts.** Concept rows are shared across documents via the `(namespace, local_name)` unique key.  This
  dramatically reduces storage when the same IFRS taxonomy is reused year after year.
* **Issuer-centric queries.** The additional entity and period indexes on `xbrl_contexts` enable responsive filtering by issuer code
  even when the dataset spans multiple decades.
* **Flexible dimensional storage.** JSON blobs keep rarely used dimensions lightweight, while the normalized `xbrl_context_dimensions`
  table is available for frequent analytical joins without exploding table count.
* **Referential integrity.** Foreign keys with cascading deletes ensure old filings can be pruned without leaving orphans, which is
  especially helpful when rolling over staging environments.

## Typical Query Patterns

* List the latest quarter for a company:
  ```sql
  SELECT d.id, d.document_name, c.period_type, c.start_date, c.end_date
  FROM xbrl_documents d
  JOIN xbrl_contexts c ON c.document_id = d.id
  WHERE c.entity_identifier = 'ID_AALI'
  ORDER BY c.end_date DESC
  LIMIT 1;
  ```
* Fetch all facts for “Total Assets” for a company over time:
  ```sql
  SELECT d.document_name, c.end_date, f.value_decimal
  FROM xbrl_facts f
  JOIN xbrl_documents d ON d.id = f.document_id
  JOIN xbrl_contexts c ON c.document_id = f.document_id AND c.context_id = f.context_id
  WHERE f.concept_local_name = 'Assets'
    AND c.entity_identifier = 'ID_AALI'
  ORDER BY c.end_date;
  ```

## Import Workflow Summary

1. Run the PHP importer with `--zip` pointing at an IDX inline XBRL archive inside `instance_files/`.
2. The importer ensures the schema is present (`sql/schema.sql`), loading the tables above.
3. Concepts, contexts, units, and facts are inserted in a single transaction so a partial failure cannot leave inconsistent data.
4. Re-importing the same filing is idempotent because of the document hash and the concept upsert logic.

This architecture keeps the database tidy as new quarters arrive each year and as you backfill filings dating to 1999.
