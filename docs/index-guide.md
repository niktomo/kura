> Japanese version: [index-guide-ja.md](index-guide-ja.md)

# Index Guide

## Overview

Kura uses **sorted indexes** stored in APCu to accelerate queries. Without indexes, every query scans all records. With indexes, Kura narrows candidates via binary search before evaluating WHERE conditions — dramatically reducing the number of records to inspect.

Indexes are not a separate data structure like a B-tree — they are sorted arrays of `[value, [ids]]` pairs stored in APCu, searched via binary search. This simple structure supports equality, range queries (`>`, `<`, `BETWEEN`), and multi-column AND conditions.

---

## Single-Column Index

### Structure

```php
kura:stations:v1.0.0:idx:prefecture → [
    ['Aichi',    [12, 45, 78]],
    ['Hokkaido', [23, 56]],
    ['Osaka',    [4, 34, 67]],
    ['Tokyo',    [1, 2, 3, 15, 28]],
]
// Sorted by value in ascending order
```

Each entry is a `[value, [ids]]` pair. The array is sorted by value, enabling binary search.

### Equality Search

```php
->where('prefecture', 'Tokyo')
```

Binary search finds `'Tokyo'` → returns `[1, 2, 3, 15, 28]` in O(log n).

### Range Queries

Kura's sorted indexes naturally support range queries via binary search:

```php
->where('price', '>', 500)         // find start position, slice to end
->where('price', '<=', 1000)       // slice from start to position
->whereBetween('price', [200, 800]) // find both bounds, slice between
```

Binary search locates the start/end positions, then slices the matching range. This works for all comparison operators: `>`, `>=`, `<`, `<=`, `BETWEEN`.

---

## Chunk Splitting (Large Datasets)

When a column has many unique values, loading the entire index from APCu can be wasteful. Kura splits large indexes into **chunks** — smaller segments with known min/max ranges stored in meta.

### Configuration

```php
// config/kura.php — global setting
'chunk_size' => 5000,  // number of unique values per chunk

// Per-table override
'tables' => [
    'stations' => [
        'chunk_size' => 10000,
    ],
],
```

`chunk_size` is the number of **unique values** per chunk (not the number of records).

### How It Works

```php
// Meta stores chunk ranges
kura:stations:v1.0.0:meta → [
    'indexes' => [
        'price' => [
            ['min' => 100,  'max' => 500],    // chunk 0
            ['min' => 501,  'max' => 1000],   // chunk 1
            ['min' => 1001, 'max' => 3000],   // chunk 2
        ],
    ],
]

// Each chunk is a separate APCu key
kura:stations:v1.0.0:idx:price:0 → [
    [100, [3, 7]],
    [200, [1, 12]],
    [500, [6, 9, 15]],
]
kura:stations:v1.0.0:idx:price:1 → [
    [501, [2, 5]],
    [700, [8, 14]],
    [1000, [4, 11]],
]
```

### Query with Chunks

```
where('price', '=', 700)
  └─ Meta → chunk 1 (501–1000) matches
       └─ Fetch chunk 1 only → binary search → [8, 14]

where('price', '>', 800)
  └─ Meta → chunk 1 + chunk 2 overlap
       └─ Fetch both chunks → binary search in each → collect IDs

where('price', 'BETWEEN', [200, 600])
  └─ Meta → chunk 0 + chunk 1 overlap
       └─ Fetch both → range slice in each → collect IDs
```

Only the relevant chunks are loaded from APCu — no need to read the entire index.

### Rules

- The **same value never spans chunk boundaries**
- Chunks are built during rebuild (not at query time)
- `null` = no chunking (single key per column)

---

## Composite Index

A hashmap for resolving **multi-column AND equality** in O(1).

### Structure

```php
kura:stations:v1.0.0:cidx:prefecture|line_id → [
    'Tokyo|1'    => [1, 2, 3],
    'Tokyo|2'    => [15],
    'Osaka|2'    => [4, 34],
    'Osaka|3'    => [67],
]
```

Key format: `{val1|val2}` string concatenation. Value: ID list. Lookup is O(1) hash access.

### When It's Used

```php
// AND equality on indexed columns → composite index O(1)
->where('prefecture', 'Tokyo')->where('line_id', 1)

// ROW constructor IN → O(1) per tuple
->whereRowValuesIn(['prefecture', 'line_id'], [['Tokyo', 1], ['Osaka', 2]])
```

### Auto-Generated Single-Column Indexes

When you declare a composite index, Kura **automatically creates single-column indexes** for each column. You don't need to declare them separately:

```php
// This declaration:
['columns' => ['prefecture', 'line_id'], 'unique' => false]

// Automatically creates:
// - idx:prefecture (single-column)
// - idx:line_id (single-column)
// - cidx:prefecture|line_id (composite)
```

### Column Order

Place the **lower cardinality column first** (fewer distinct values):

```php
// Good: prefecture (~47 values) before line_id (~hundreds)
['columns' => ['prefecture', 'line_id'], 'unique' => false]
```

---

## Multi-Column WHERE (Intersection)

When multiple indexed columns appear in AND conditions:

```
where('prefecture', 'Tokyo')->where('line_id', 1)
  ├─ Composite index exists? → cidx lookup O(1) ✓
  └─ No composite? →
       ├─ prefecture index → [1, 2, 3, 15, 28]
       ├─ line_id index → [1, 2, 3, 4, 34]
       └─ array_intersect_key → [1, 2, 3]
```

ID lists from each index are converted to hashmaps via `array_flip`, then intersected with `array_intersect_key`.

---

## Declaring Indexes

Indexes are declared via `LoaderInterface::indexes()` — it's the Loader's responsibility:

```php
use Kura\Index\IndexDefinition;

// In CsvLoader
$loader = new CsvLoader(
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
    indexDefinitions: [
        ['columns' => ['prefecture'], 'unique' => false],
        ['columns' => ['line_id'], 'unique' => false],
        ['columns' => ['prefecture', 'line_id'], 'unique' => false],  // composite
        ['columns' => ['code'], 'unique' => true],                    // unique
    ],
);

// Or using IndexDefinition factory
$indexes = [
    IndexDefinition::nonUnique('prefecture'),
    IndexDefinition::nonUnique('line_id'),
    IndexDefinition::nonUnique('prefecture', 'line_id'),  // composite
    IndexDefinition::unique('code'),
];
```

### Unique vs Non-Unique

| Type | Returns | Use case |
|---|---|---|
| `unique: true` | Single ID | Primary key alternatives, unique codes |
| `unique: false` | ID list | Category, status, foreign keys |

---

## When Indexes Are Used

| Operator | Index used? | How |
|---|---|---|
| `=` | Yes | Binary search O(log n) |
| `!=`, `<>` | No | Full scan (negation can't narrow) |
| `>`, `>=`, `<`, `<=` | Yes | Binary search → slice |
| `BETWEEN` | Yes | Binary search → range slice |
| `IN` | Yes | Binary search per value |
| `NOT IN` | No | Full scan |
| `LIKE` | No | Full scan (pattern matching) |
| AND | Yes | Intersection of each index result |
| OR (all indexed) | Yes | Union of each index result |
| OR (any not indexed) | No | Abandon index, full scan |
| ROW IN + composite | Yes | Composite hashmap O(1) per tuple |
| ROW NOT IN | No | Full scan |

**Important**: Indexes only narrow candidates. All WHERE conditions are always re-evaluated via closures on every record — indexes are an optimization, not a filter replacement.

---

## Practical Example

A stations table with 9,000+ records:

```php
// Index declaration
$loader = new CsvLoader(
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
    indexDefinitions: [
        ['columns' => ['prefecture'], 'unique' => false],      // ~47 values
        ['columns' => ['line_id'], 'unique' => false],          // ~300 values
        ['columns' => ['prefecture', 'line_id'], 'unique' => false],  // composite
    ],
);

// Config: chunk large indexes
'tables' => [
    'stations' => [
        'chunk_size' => 1000,  // split indexes with 1000+ unique values
    ],
],
```

This creates:
- `idx:prefecture` — 47 entries, no chunking needed
- `idx:line_id` — 300 entries, no chunking needed
- `cidx:prefecture|line_id` — O(1) composite hashmap

Queries that benefit:
```php
// Uses idx:prefecture → binary search
Kura::table('stations')->where('prefecture', 'Tokyo')->get();

// Uses cidx:prefecture|line_id → O(1)
Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->where('line_id', 1)
    ->get();

// Uses idx:line_id → range slice
Kura::table('stations')
    ->whereBetween('line_id', [1, 10])
    ->get();

// No index on 'name' → full scan (still correct, just slower)
Kura::table('stations')->where('name', 'Tokyo')->get();
```
