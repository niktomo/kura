> English version: [index-guide.md](index-guide.md)

# インデックスガイド

## 概要

Kura は APCu に保存した**ソート済みインデックス**を使ってクエリを高速化します。インデックスがなければ全レコードを走査しますが、インデックスがあれば二分探索で候補を絞り込んでから WHERE 条件を評価 — 検査するレコード数を劇的に削減します。

インデックスは B-tree のような別構造ではなく、`[value, [ids]]` ペアのソート済み配列を APCu に保存し、二分探索で検索するシンプルな構造です。等価検索、範囲クエリ（`>`、`<`、`BETWEEN`）、複合カラム AND 条件に対応します。

---

## 単カラムインデックス

### 構造

```php
kura:stations:v1.0.0:idx:prefecture → [
    ['Aichi',    [12, 45, 78]],
    ['Hokkaido', [23, 56]],
    ['Osaka',    [4, 34, 67]],
    ['Tokyo',    [1, 2, 3, 15, 28]],
]
// value 昇順ソート済み
```

各エントリは `[value, [ids]]` ペア。配列は value でソートされており、二分探索が可能です。

### 等価検索

```php
->where('prefecture', 'Tokyo')
```

二分探索で `'Tokyo'` を検索 → `[1, 2, 3, 15, 28]` を O(log n) で返却。

### 範囲クエリ

Kura のソート済みインデックスは二分探索により範囲クエリに自然に対応します:

```php
->where('price', '>', 500)         // 開始位置を特定、末尾まで slice
->where('price', '<=', 1000)       // 先頭から位置まで slice
->whereBetween('price', [200, 800]) // 両端を特定、間を slice
```

二分探索で開始/終了位置を特定し、該当範囲を slice します。すべての比較演算子に対応: `>`、`>=`、`<`、`<=`、`BETWEEN`。

---

## チャンク分割（大規模データセット）

カラムのユニーク値が多い場合、インデックス全体を APCu から読み込むのは無駄です。Kura は大きなインデックスを**チャンク**に分割 — min/max 範囲を meta に保持する小さなセグメントにします。

### 設定

```php
// config/kura.php — グローバル設定
'chunk_size' => 5000,  // 1チャンクあたりのユニーク値の数

// テーブル単位のオーバーライド
'tables' => [
    'stations' => [
        'chunk_size' => 10000,
    ],
],
```

`chunk_size` はレコード数ではなく**ユニーク値の数**です。

### 動作

```php
// meta にチャンクの範囲を保存
kura:stations:v1.0.0:meta → [
    'indexes' => [
        'price' => [
            ['min' => 100,  'max' => 500],    // chunk 0
            ['min' => 501,  'max' => 1000],   // chunk 1
            ['min' => 1001, 'max' => 3000],   // chunk 2
        ],
    ],
]

// 各チャンクは別々の APCu キー
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

### チャンクを使ったクエリ

```
where('price', '=', 700)
  └─ meta → chunk 1 (501〜1000) がマッチ
       └─ chunk 1 のみ取得 → 二分探索 → [8, 14]

where('price', '>', 800)
  └─ meta → chunk 1 + chunk 2 がオーバーラップ
       └─ 両チャンクを取得 → 各チャンク内で二分探索 → ID を収集

where('price', 'BETWEEN', [200, 600])
  └─ meta → chunk 0 + chunk 1 がオーバーラップ
       └─ 両方を取得 → 各チャンク内で範囲 slice → ID を収集
```

関連するチャンクのみ APCu から読み込み — インデックス全体を読む必要はありません。

### ルール

- **同一値は必ず同じチャンクに収まる**（チャンク境界をまたがない）
- チャンクは rebuild 時に構築される（クエリ時ではない）
- `null` = チャンクなし（カラムごとに単一キー）

---

## Composite Index

**複合カラムの AND equality** を O(1) で解決するための hashmap。

### 構造

```php
kura:stations:v1.0.0:cidx:prefecture|line_id → [
    'Tokyo|1'    => [1, 2, 3],
    'Tokyo|2'    => [15],
    'Osaka|2'    => [4, 34],
    'Osaka|3'    => [67],
]
```

キー形式: `{val1|val2}` の文字列結合。値: ID リスト。ルックアップは O(1) のハッシュアクセス。

### 使用される場面

```php
// インデックス付きカラムの AND equality → composite index O(1)
->where('prefecture', 'Tokyo')->where('line_id', 1)

// ROW constructor IN → タプルごとに O(1)
->whereRowValuesIn(['prefecture', 'line_id'], [['Tokyo', 1], ['Osaka', 2]])
```

### 自動生成される単カラムインデックス

composite index を宣言すると、Kura は各カラムの**単カラムインデックスを自動的に作成**します。個別に宣言する必要はありません:

```php
// この宣言:
['columns' => ['prefecture', 'line_id'], 'unique' => false]

// 自動的に作成:
// - idx:prefecture（単カラム）
// - idx:line_id（単カラム）
// - cidx:prefecture|line_id（composite）
```

### カラムの順序

**カーディナリティが低いカラム**（ユニーク値の少ない方）を先頭に:

```php
// 良い例: prefecture（〜47値）を line_id（〜数百値）の前に
['columns' => ['prefecture', 'line_id'], 'unique' => false]
```

---

## 複数カラム WHERE（intersection）

AND 条件に複数のインデックス付きカラムがある場合:

```
where('prefecture', 'Tokyo')->where('line_id', 1)
  ├─ composite index がある? → cidx ルックアップ O(1) ✓
  └─ ない場合 →
       ├─ prefecture index → [1, 2, 3, 15, 28]
       ├─ line_id index → [1, 2, 3, 4, 34]
       └─ array_intersect_key → [1, 2, 3]
```

各インデックスの ID リストを `array_flip` で hashmap に変換し、`array_intersect_key` で交差を取ります。

---

## インデックスの宣言

インデックスは `LoaderInterface::indexes()` で宣言します — Loader 側の責務です:

```php
use Kura\Index\IndexDefinition;

// CsvLoader の場合
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

// IndexDefinition ファクトリを使う場合
$indexes = [
    IndexDefinition::nonUnique('prefecture'),
    IndexDefinition::nonUnique('line_id'),
    IndexDefinition::nonUnique('prefecture', 'line_id'),  // composite
    IndexDefinition::unique('code'),
];
```

### Unique vs Non-Unique

| タイプ | 返却値 | 用途 |
|---|---|---|
| `unique: true` | 単一 ID | 主キー代替、ユニークコード |
| `unique: false` | ID リスト | カテゴリ、ステータス、外部キー |

---

## インデックスが使われる条件

| 演算子 | インデックス使用 | 方法 |
|---|---|---|
| `=` | はい | 二分探索 O(log n) |
| `!=`, `<>` | いいえ | full scan（否定では絞り込めない） |
| `>`, `>=`, `<`, `<=` | はい | 二分探索 → slice |
| `BETWEEN` | はい | 二分探索 → 範囲 slice |
| `IN` | はい | 値ごとに二分探索 |
| `NOT IN` | いいえ | full scan |
| `LIKE` | いいえ | full scan（パターンマッチング） |
| AND | はい | 各インデックス結果の intersection |
| OR（全て indexed） | はい | 各インデックス結果の union |
| OR（一部 not indexed） | いいえ | インデックスを放棄、full scan |
| ROW IN + composite | はい | composite hashmap O(1) per tuple |
| ROW NOT IN | いいえ | full scan |

**重要**: インデックスは候補の絞り込みのみ。全 WHERE 条件は常にクロージャで全レコードに対して再評価されます — インデックスは最適化であり、フィルタの代替ではありません。

---

## 実用例

9,000件以上の駅テーブル:

```php
// インデックス宣言
$loader = new CsvLoader(
    tableDirectory: base_path('data/stations'),
    resolver: $resolver,
    indexDefinitions: [
        ['columns' => ['prefecture'], 'unique' => false],      // 〜47値
        ['columns' => ['line_id'], 'unique' => false],          // 〜300値
        ['columns' => ['prefecture', 'line_id'], 'unique' => false],  // composite
    ],
);

// Config: 大きなインデックスをチャンク分割
'tables' => [
    'stations' => [
        'chunk_size' => 1000,  // 1000以上のユニーク値があればインデックスを分割
    ],
],
```

作成されるインデックス:
- `idx:prefecture` — 47エントリ、チャンク不要
- `idx:line_id` — 300エントリ、チャンク不要
- `cidx:prefecture|line_id` — O(1) composite hashmap

恩恵を受けるクエリ:
```php
// idx:prefecture を使用 → 二分探索
Kura::table('stations')->where('prefecture', 'Tokyo')->get();

// cidx:prefecture|line_id を使用 → O(1)
Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->where('line_id', 1)
    ->get();

// idx:line_id を使用 → 範囲 slice
Kura::table('stations')
    ->whereBetween('line_id', [1, 10])
    ->get();

// 'name' にインデックスなし → full scan（正しい結果だが遅い）
Kura::table('stations')->where('name', 'Tokyo')->get();
```
