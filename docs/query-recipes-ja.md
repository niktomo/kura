> English version: [query-recipes.md](query-recipes.md)

# クエリレシピ集

Kura の実践的なクエリパターン集です。すべての例で `Kura` ファサードを使用しています。

完全な API 対応表は [Laravel Builder カバレッジ](laravel-builder-coverage-ja.md) を参照してください。

---

## 基本的な取得

### find — 主キー検索

```php
$station = Kura::table('stations')->find(1);
// 配列または null を返す

$station = Kura::table('stations')->findOr(999, fn() => ['id' => 0, 'name' => 'Unknown']);
// 見つからない場合はフォールバック値を返す
```

### first — 最初の一致レコード

```php
$station = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orderBy('name')
    ->first();
```

### sole — ちょうど1件の一致

```php
$station = Kura::table('stations')
    ->where('code', 'TKY001')
    ->sole();
// 0件の場合 RecordsNotFoundException をスロー
// 2件以上の場合 MultipleRecordsFoundException をスロー
```

### get — 一致する全レコード

```php
$stations = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->get();
// レコードの配列を返す
```

### cursor — Generator（省メモリ）

```php
foreach (Kura::table('stations')->where('prefecture', 'Tokyo')->cursor() as $station) {
    // 1件ずつ処理 — 全件をメモリに載せない
}
```

---

## フィルタリング

### 基本的な WHERE

```php
// 等価（デフォルト演算子）
->where('prefecture', 'Tokyo')

// 比較演算子
->where('price', '>', 500)
->where('price', '>=', 500)
->where('price', '<', 1000)
->where('price', '<=', 1000)
->where('price', '!=', 0)
```

### OR 条件

```php
$stations = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orWhere('prefecture', 'Osaka')
    ->get();
```

### ネストされたグループ

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

### 否定

```php
// クロージャを使った whereNot
$stations = Kura::table('stations')
    ->whereNot(function ($q) {
        $q->where('status', 'closed');
    })
    ->get();
```

---

## NULL の扱い

```php
// カラムが NULL のレコード
->whereNull('deleted_at')

// カラムが NULL でないレコード
->whereNotNull('email')

// NULL 安全な等価比較（null === null は true）
->whereNullSafeEquals('manager_id', null)
->whereNullSafeEquals('manager_id', 5)
```

---

## 範囲クエリ

```php
// BETWEEN（両端含む）
$mid = Kura::table('products')
    ->whereBetween('price', [500, 2000])
    ->get();

// NOT BETWEEN
$extreme = Kura::table('products')
    ->whereNotBetween('price', [500, 2000])
    ->get();

// カラム値が他の2カラムの間にあるか
$inRange = Kura::table('events')
    ->whereBetweenColumns('target_date', ['start_date', 'end_date'])
    ->get();

// スカラー値が2つのカラム値の間にあるか
$active = Kura::table('campaigns')
    ->whereValueBetween(now()->toDateString(), ['start_date', 'end_date'])
    ->get();
```

---

## パターンマッチング

```php
// 大文字小文字を区別しない LIKE（デフォルト）
$results = Kura::table('products')
    ->whereLike('name', '%widget%')
    ->get();

// 大文字小文字を区別する LIKE
$results = Kura::table('products')
    ->whereLike('name', '%Widget%', caseSensitive: true)
    ->get();

// NOT LIKE
$results = Kura::table('products')
    ->whereNotLike('name', '%test%')
    ->get();
```

---

## コレクション操作

### whereIn

```php
$stations = Kura::table('stations')
    ->whereIn('prefecture', ['Tokyo', 'Osaka', 'Aichi'])
    ->get();
```

### クロステーブルフィルタリング（遅延サブクエリ）

```php
// 関東路線の駅を取得 — クロージャによるクロステーブルフィルタリング
$stations = Kura::table('stations')
    ->whereIn('line_id', fn() => Kura::table('lines')
        ->where('region', 'Kanto')
        ->pluck('id'))
    ->get();
```

クロージャは遅延評価 — 内部クエリは必要な時にだけ実行されます。

### whereNotIn

```php
$stations = Kura::table('stations')
    ->whereNotIn('status', ['closed', 'suspended'])
    ->get();
```

---

## 複数カラム条件

### whereAll — 全カラムが一致

```php
// WHERE name = 'Tokyo' AND code = 'Tokyo'
$results = Kura::table('stations')
    ->whereAll(['name', 'code'], 'Tokyo')
    ->get();
```

### whereAny — いずれかのカラムが一致

```php
// WHERE name LIKE '%tokyo%' OR code LIKE '%tokyo%'
$results = Kura::table('stations')
    ->whereAny(['name', 'code'], 'like', '%tokyo%')
    ->get();
```

### whereNone — どのカラムも一致しない

```php
// WHERE NOT (name = 'test' OR code = 'test')
$results = Kura::table('stations')
    ->whereNone(['name', 'code'], 'test')
    ->get();
```

---

## カスタム述語

### whereFilter — 生の PHP 述語

```php
// 任意の PHP ロジックでフィルタ
$stations = Kura::table('stations')
    ->whereFilter(fn($r) => str_starts_with($r['name'], '新'))
    ->get();

$products = Kura::table('products')
    ->whereFilter(fn($r) => $r['price'] * $r['quantity'] > 10000)
    ->get();
```

### whereExists — レコードレベルの述語

```php
// クロージャは各レコードを受け取り、bool を返す
$results = Kura::table('products')
    ->whereExists(fn($record) => in_array($record['category'], $allowedCategories))
    ->get();
```

注意: SQL の EXISTS とは異なり、クロージャは現在のレコードを配列として受け取ります。

---

## ROW Constructor IN（Kura 拡張）

複数カラムのタプルマッチング — MySQL の `(col1, col2) IN ((v1, v2), ...)` に相当:

```php
// (prefecture, line_id) の組み合わせで駅を検索
$stations = Kura::table('stations')
    ->whereRowValuesIn(
        ['prefecture', 'line_id'],
        [['Tokyo', 1], ['Osaka', 2], ['Aichi', 3]]
    )
    ->get();

// NOT IN バリアント
$stations = Kura::table('stations')
    ->whereRowValuesNotIn(
        ['prefecture', 'line_id'],
        [['Tokyo', 1]]
    )
    ->get();
```

対象カラムに composite index があれば、タプルごとに O(1) でルックアップされます。

---

## カラム比較

```php
// 2つのカラムを比較
->whereColumn('updated_at', '>', 'created_at')

// カラム値が他の2カラムの間にあるか
->whereBetweenColumns('score', ['min_score', 'max_score'])

// スカラー値が2つのカラムの間にあるか
->whereValueBetween(50, ['min_age', 'max_age'])
```

---

## ソート

```php
// 単一カラム
->orderBy('name')
->orderByDesc('price')

// 複数カラム
->orderBy('prefecture')->orderBy('name')

// ショートカット
->latest('created_at')   // orderByDesc('created_at')
->oldest('created_at')   // orderBy('created_at')

// ランダム順
->inRandomOrder()

// リセットして再ソート
->reorder('price', 'asc')
```

---

## ページネーション

### paginate — 総件数付き

```php
$page = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orderBy('name')
    ->paginate(perPage: 20, page: 1);

// LengthAwarePaginator を返す — Blade の {{ $page->links() }} と互換
$page->total();       // 一致する総レコード数
$page->lastPage();    // 総ページ数
$page->items();       // 現在ページのレコード
```

### simplePaginate — 総件数なし

```php
$page = Kura::table('stations')
    ->orderBy('name')
    ->simplePaginate(perPage: 20, page: 2);

// Paginator を返す — 総件数なし（大規模データセットで高速）
$page->hasMorePages();
```

### カーソルスタイルのページネーション

```php
// ID 100 以降の20件を取得
$next = Kura::table('stations')
    ->forPageAfterId(perPage: 20, lastId: 100)
    ->get();

// ID 100 以前の20件を取得（降順）
$prev = Kura::table('stations')
    ->forPageBeforeId(perPage: 20, lastId: 100)
    ->get();
```

---

## 集計

```php
$count = Kura::table('stations')->where('prefecture', 'Tokyo')->count();
$min   = Kura::table('products')->min('price');
$max   = Kura::table('products')->max('price');
$sum   = Kura::table('products')->where('category', 'electronics')->sum('price');
$avg   = Kura::table('products')->avg('price');

// 存在チェック
$exists = Kura::table('stations')->where('prefecture', 'Okinawa')->exists();
$empty  = Kura::table('stations')->where('prefecture', 'Atlantis')->doesntExist();
```

---

## ユーティリティ

### pluck — カラム値を抽出

```php
$names = Kura::table('stations')->pluck('name');
// ['Tokyo', 'Shibuya', 'Shinjuku', ...]

$nameById = Kura::table('stations')->pluck('name', 'id');
// [1 => 'Tokyo', 2 => 'Shibuya', ...]
```

### value — 単一カラムの値

```php
$name = Kura::table('stations')
    ->where('id', 1)
    ->value('name');
// 'Tokyo'
```

### implode — カラム値を結合

```php
$csv = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->implode('name', ', ');
// 'Tokyo, Shibuya, Shinjuku, ...'
```

### clone — ビルダー状態のコピー

```php
$base = Kura::table('stations')->where('prefecture', 'Tokyo');

$count = $base->clone()->count();
$first = $base->clone()->orderBy('name')->first();

// cloneWithout — 特定の状態を除いてコピー
$noOrder = $base->clone()->orderBy('name')->cloneWithout(['orders']);
```
