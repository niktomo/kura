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

Indexes are declared via `LoaderInterface::indexes()` — it's the Loader's responsibility.

### CSV and Database Loaders

All loaders (CsvLoader, EloquentLoader, QueryBuilderLoader) read column and index definitions from CSV files in the table directory:

```
data/stations/
├── defines.csv    # column type definitions  (required)
├── indexes.csv    # index declarations       (optional — omit if no indexes needed)
└── data.csv       # CSV data                 (CsvLoader only)
```

**defines.csv format:**
```csv
column,type,description
id,int,Primary key
prefecture,string,Prefecture name
line_id,int,Line ID
code,string,Station code
price,int,Price
```

- `column`: column name
- `type`: `int`, `string`, `float`, or `bool`
- `description`: free text, ignored by Kura

**indexes.csv format:**
```csv
columns,unique
prefecture,false
line_id,false
prefecture|line_id,false
code,true
```

- `columns`: single column name, or `col1|col2` for composite
- `unique`: `true` or `false`

**CsvLoader:**
```php
$loader = new CsvLoader(
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
);
```

**EloquentLoader:**
```php
$loader = new EloquentLoader(
    query: Station::query(),
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
);
```

**QueryBuilderLoader:**
```php
$loader = new QueryBuilderLoader(
    query: DB::table('stations'),
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
);
```

> **Note**: Kura's indexes are **independent of your database indexes**. A column does not need a DB index to be indexed in Kura's APCu cache. That said, columns that are worth indexing in Kura (high selectivity, frequently queried) are often worth indexing in the DB too — they are two separate optimizations.

### Unique vs Non-Unique

| Type | Use case |
|---|---|
| `unique: true` | Primary key alternatives, unique codes |
| `unique: false` | Category, status, foreign keys |

> **`unique` is a documentation hint, not a constraint.**
> Kura does not enforce uniqueness. If duplicate values exist in the data, the index stores all matching IDs for that value regardless of the flag.
> Use `unique: true` to communicate intent — it has no effect on query behavior or index structure.

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

**data/stations/indexes.csv:**
```csv
columns,unique
prefecture,false
line_id,false
prefecture|line_id,false
```

```php
$loader = new CsvLoader(
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
);
```

This creates:
- `idx:prefecture` — 47 entries (one APCu key)
- `idx:line_id` — 300 entries (one APCu key)
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

---

## Index Strategy

### Which columns to index

Index columns that appear frequently in `where` conditions and have **high selectivity** (the value narrows the result set significantly).

| Column type | Index? | Reason |
|---|---|---|
| Primary key alternative (code, slug) | ✅ unique | O(1) single-record lookup |
| Foreign key / category (country, status) | ✅ non-unique | Reduces scan by cardinality |
| Boolean flag (active, deleted) | ❌ rarely useful | Only 2 values — low selectivity, large result sets |
| Free-text (name, description) | ❌ | Kura doesn't support full-text; LIKE forces full scan anyway |
| Numeric range (price, age) | ✅ if queried with `>` / `BETWEEN` | Binary search for range slicing |

### When to add a composite index

Add a composite index when **both columns appear together in AND equality conditions** and neither alone narrows the candidates sufficiently.

```php
// Good candidate for composite — always queried together
->where('prefecture', 'Tokyo')->where('line_id', 1)

// Not a good candidate — 'prefecture' alone is selective enough
->where('prefecture', 'Tokyo')->where('active', true)
```

**Do not** add a composite index just because two columns exist. Composite indexes have a cost:

- More APCu keys written at rebuild time
- The composite hashmap holds all unique combinations — if cardinality is high (many combinations), the stored map is large and deserialization is expensive

### Cardinality and composite index efficiency

The composite index is most effective when the **combination has lower cardinality than the individual columns**.

```
prefecture: 47 values
line_id: 300 values
prefecture × line_id combinations: ~900 (most stations are in one prefecture+line)
→ Good: composite narrows directly to a small result set
```

```
user_id: 100,000 values
product_id: 50,000 values
user_id × product_id combinations: millions
→ Bad: composite map is enormous; full scan may be faster
```

Rule of thumb: **composite index is worth it when the expected combination count is under ~10,000**.

### When composite index is counterproductive

When a query matches **most of the dataset**, the composite index loses its advantage:

- The hashmap must be deserialized entirely before any lookup
- All matched IDs still need to be fetched from APCu record-by-record
- Full scan with sequential access may be faster than large hashmap deserialization

**Example**: If `whereRowValuesIn` tuples cover 80%+ of all records, skip the composite index and rely on single-column indexes + WhereEvaluator filtering.

### 3+ column composites

Kura supports composite indexes on 3+ columns, but they are rarely worth the added complexity:

- The combination space grows exponentially
- Single-column indexes for each column are auto-created anyway — use those + WhereEvaluator
- Reserve 3-column composites for very specific, high-frequency lookup patterns with provably low combination counts
