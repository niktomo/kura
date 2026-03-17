> Japanese version: [query-recipes-ja.md](query-recipes-ja.md)

# Query Recipes

Practical query patterns for Kura. All examples use the `Kura` facade.

For the full API compatibility table, see [Laravel Builder Coverage](laravel-builder-coverage.md).

---

## Basic Retrieval

### find — Primary Key Lookup

```php
$station = Kura::table('stations')->find(1);
// Returns array or null

$station = Kura::table('stations')->findOr(999, fn() => ['id' => 0, 'name' => 'Unknown']);
// Returns fallback value if not found
```

### first — First Matching Record

```php
$station = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orderBy('name')
    ->first();
```

### sole — Exactly One Match

```php
$station = Kura::table('stations')
    ->where('code', 'TKY001')
    ->sole();
// Throws RecordsNotFoundException if 0 results
// Throws MultipleRecordsFoundException if 2+ results
```

### get — All Matching Records

```php
$stations = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->get();
// Returns array of records
```

### cursor — Generator (Low Memory)

```php
foreach (Kura::table('stations')->where('prefecture', 'Tokyo')->cursor() as $station) {
    // Process one record at a time — never loads all into memory
}
```

---

## Filtering

### Basic WHERE

```php
// Equality (default operator)
->where('prefecture', 'Tokyo')

// Comparison operators
->where('price', '>', 500)
->where('price', '>=', 500)
->where('price', '<', 1000)
->where('price', '<=', 1000)
->where('price', '!=', 0)
```

### OR Conditions

```php
$stations = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orWhere('prefecture', 'Osaka')
    ->get();
```

### Nested Groups

```php
// WHERE (prefecture = 'Tokyo' OR prefecture = 'Osaka') AND line_id = 1
$stations = Kura::table('stations')
    ->where(function ($q) {
        $q->where('prefecture', 'Tokyo')
          ->orWhere('prefecture', 'Osaka');
    })
    ->where('line_id', 1)
    ->get();
```

### Negation

```php
// whereNot with closure
$stations = Kura::table('stations')
    ->whereNot(function ($q) {
        $q->where('status', 'closed');
    })
    ->get();
```

---

## Null Handling

```php
// Records where column IS NULL
->whereNull('deleted_at')

// Records where column IS NOT NULL
->whereNotNull('email')

// NULL-safe equality (null === null is true)
->whereNullSafeEquals('manager_id', null)
->whereNullSafeEquals('manager_id', 5)
```

---

## Range Queries

```php
// BETWEEN (inclusive)
$mid = Kura::table('products')
    ->whereBetween('price', [500, 2000])
    ->get();

// NOT BETWEEN
$extreme = Kura::table('products')
    ->whereNotBetween('price', [500, 2000])
    ->get();

// Column value between two other columns
$inRange = Kura::table('events')
    ->whereBetweenColumns('target_date', ['start_date', 'end_date'])
    ->get();

// Scalar between two column values
$active = Kura::table('campaigns')
    ->whereValueBetween(now()->toDateString(), ['start_date', 'end_date'])
    ->get();
```

---

## Pattern Matching

```php
// Case-insensitive LIKE (default)
$results = Kura::table('products')
    ->whereLike('name', '%widget%')
    ->get();

// Case-sensitive LIKE
$results = Kura::table('products')
    ->whereLike('name', '%Widget%', caseSensitive: true)
    ->get();

// NOT LIKE
$results = Kura::table('products')
    ->whereNotLike('name', '%test%')
    ->get();
```

---

## Collection Operations

### whereIn

```php
$stations = Kura::table('stations')
    ->whereIn('prefecture', ['Tokyo', 'Osaka', 'Aichi'])
    ->get();
```

### Cross-Table Filtering (Lazy Subquery)

```php
// Get stations on Kanto lines — cross-table filtering via closure
$stations = Kura::table('stations')
    ->whereIn('line_id', fn() => Kura::table('lines')
        ->where('region', 'Kanto')
        ->pluck('id'))
    ->get();
```

The closure is evaluated lazily — the inner query runs only when needed.

### whereNotIn

```php
$stations = Kura::table('stations')
    ->whereNotIn('status', ['closed', 'suspended'])
    ->get();
```

---

## Multi-Column Conditions

### whereAll — All Columns Must Match

```php
// WHERE name = 'Tokyo' AND code = 'Tokyo'
$results = Kura::table('stations')
    ->whereAll(['name', 'code'], 'Tokyo')
    ->get();
```

### whereAny — Any Column May Match

```php
// WHERE name LIKE '%tokyo%' OR code LIKE '%tokyo%'
$results = Kura::table('stations')
    ->whereAny(['name', 'code'], 'like', '%tokyo%')
    ->get();
```

### whereNone — No Column Should Match

```php
// WHERE NOT (name = 'test' OR code = 'test')
$results = Kura::table('stations')
    ->whereNone(['name', 'code'], 'test')
    ->get();
```

---

## Custom Predicates

### whereFilter — Raw PHP Predicate

```php
// Any PHP logic as a filter
$stations = Kura::table('stations')
    ->whereFilter(fn($r) => str_starts_with($r['name'], 'Shin'))
    ->get();

$products = Kura::table('products')
    ->whereFilter(fn($r) => $r['price'] * $r['quantity'] > 10000)
    ->get();
```

### whereExists — Record-Level Predicate

```php
// Closure receives each record, returns bool
$results = Kura::table('products')
    ->whereExists(fn($record) => in_array($record['category'], $allowedCategories))
    ->get();
```

Note: Unlike SQL EXISTS, the closure receives the current record as an array.

---

## ROW Constructor IN (Kura Extension)

Multi-column tuple matching — equivalent to MySQL's `(col1, col2) IN ((v1, v2), ...)`:

```php
// Find stations by (prefecture, line_id) combinations
$stations = Kura::table('stations')
    ->whereRowValuesIn(
        ['prefecture', 'line_id'],
        [['Tokyo', 1], ['Osaka', 2], ['Aichi', 3]]
    )
    ->get();

// NOT IN variant
$stations = Kura::table('stations')
    ->whereRowValuesNotIn(
        ['prefecture', 'line_id'],
        [['Tokyo', 1]]
    )
    ->get();
```

If a composite index exists for these columns, lookup is O(1) per tuple.

---

## Column Comparison

```php
// Compare two columns
->whereColumn('updated_at', '>', 'created_at')

// Column value between two other columns
->whereBetweenColumns('score', ['min_score', 'max_score'])

// Scalar value between two columns
->whereValueBetween(50, ['min_age', 'max_age'])
```

---

## Sorting

```php
// Single column
->orderBy('name')
->orderByDesc('price')

// Multiple columns
->orderBy('prefecture')->orderBy('name')

// Shortcuts
->latest('created_at')   // orderByDesc('created_at')
->oldest('created_at')   // orderBy('created_at')

// Random order
->inRandomOrder()

// Reset and re-order
->reorder('price', 'asc')
```

---

## Pagination

### paginate — With Total Count

```php
$page = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orderBy('name')
    ->paginate(perPage: 20, page: 1);

// Returns LengthAwarePaginator — compatible with Blade {{ $page->links() }}
$page->total();       // total matching records
$page->lastPage();    // total pages
$page->items();       // current page records
```

### simplePaginate — Without Total Count

```php
$page = Kura::table('stations')
    ->orderBy('name')
    ->simplePaginate(perPage: 20, page: 2);

// Returns Paginator — no total count (faster for large datasets)
$page->hasMorePages();
```

### Cursor-Style Pagination

```php
// Get 20 records after ID 100
$next = Kura::table('stations')
    ->forPageAfterId(perPage: 20, lastId: 100)
    ->get();

// Get 20 records before ID 100 (descending)
$prev = Kura::table('stations')
    ->forPageBeforeId(perPage: 20, lastId: 100)
    ->get();
```

---

## Aggregates

```php
$count = Kura::table('stations')->where('prefecture', 'Tokyo')->count();
$min   = Kura::table('products')->min('price');
$max   = Kura::table('products')->max('price');
$sum   = Kura::table('products')->where('category', 'electronics')->sum('price');
$avg   = Kura::table('products')->avg('price');

// Existence checks
$exists = Kura::table('stations')->where('prefecture', 'Okinawa')->exists();
$empty  = Kura::table('stations')->where('prefecture', 'Atlantis')->doesntExist();
```

---

## Utility

### pluck — Extract Column Values

```php
$names = Kura::table('stations')->pluck('name');
// ['Tokyo', 'Shibuya', 'Shinjuku', ...]

$nameById = Kura::table('stations')->pluck('name', 'id');
// [1 => 'Tokyo', 2 => 'Shibuya', ...]
```

### value — Single Column Value

```php
$name = Kura::table('stations')
    ->where('id', 1)
    ->value('name');
// 'Tokyo'
```

### implode — Join Column Values

```php
$csv = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->implode('name', ', ');
// 'Tokyo, Shibuya, Shinjuku, ...'
```

### clone — Copy Builder State

```php
$base = Kura::table('stations')->where('prefecture', 'Tokyo');

$count = $base->clone()->count();
$first = $base->clone()->orderBy('name')->first();

// cloneWithout — copy without certain state
$noOrder = $base->clone()->orderBy('name')->cloneWithout(['orders']);
```
