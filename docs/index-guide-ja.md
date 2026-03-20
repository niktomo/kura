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

```

作成されるインデックス:
- `idx:prefecture` — 47エントリ（APCu 単一キー）
- `idx:line_id` — 300エントリ（APCu 単一キー）
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

---

## インデックス戦略

### どのカラムをインデックスするか

`where` 条件に頻出し、**セレクティビティが高い**（値が結果件数を大きく絞れる）カラムにインデックスを付けます。

| カラムの種類 | インデックス | 理由 |
|---|---|---|
| コード・スラッグ（一意識別子） | ✅ unique | O(1) の単一レコード取得 |
| 外部キー・カテゴリ（国、ステータス） | ✅ non-unique | カーディナリティで走査を削減 |
| boolean フラグ（active, deleted） | ❌ ほぼ無意味 | 値が2種類 → セレクティビティが低く結果件数が多い |
| 自由文（name, description） | ❌ | LIKE は full scan になるため不要 |
| 数値範囲（price, age） | ✅ `>` / `BETWEEN` で使う場合 | 二分探索で範囲 slice |

### composite index を付けるべきとき

**AND equality で2カラムが常に一緒に使われ**、どちらか1カラムだけでは十分に絞れない場合に composite index を追加します。

```php
// composite の有力候補 — 常に一緒に使われる
->where('prefecture', 'Tokyo')->where('line_id', 1)

// 向いていない — prefecture だけで十分絞れる
->where('prefecture', 'Tokyo')->where('active', true)
```

2カラムが存在するからといって安易に composite index を付けないでください。コストがあります：

- rebuild 時に多くの APCu キーが書き込まれる
- composite hashmap は全ユニーク組み合わせを保持する — カーディナリティが高いと巨大になり、デシリアライズコストが高くなる

### カーディナリティと composite index の効率

composite index が最も効果的なのは、**組み合わせのカーディナリティが個別カラムよりも低い**場合です。

```
prefecture: 47 値
line_id: 300 値
有効な組み合わせ: ~900（多くの駅は特定の都道府県・路線に集中）
→ 良い: composite で直接小さな結果セットに絞れる
```

```
user_id: 100,000 値
product_id: 50,000 値
有効な組み合わせ: 数百万
→ 悪い: hashmap が巨大になり full scan の方が速い場合がある
```

目安：**組み合わせ数が ~10,000 以下なら composite index は効果的**。

### composite index が逆効果になるケース

クエリが**データセットの大部分にマッチする**場合、composite index の優位性がなくなります：

- hashmap を全件デシリアライズしてからルックアップする
- マッチした全 ID を APCu から1件ずつフェッチするコストは変わらない
- 順次アクセスする full scan の方がデシリアライズオーバーヘッドがない分速いことがある

**例**：`whereRowValuesIn` のタプルが全レコードの 80% 以上にマッチする場合、composite index を使わず単カラム index + WhereEvaluator フィルタリングに任せる。

### 3カラム以上の composite

Kura は 3カラム以上の composite index をサポートしますが、費用対効果は低い場合がほとんどです：

- 組み合わせ空間が指数的に増加する
- 各カラムの単カラム index は自動生成されるため、それ + WhereEvaluator で十分なことが多い
- 組み合わせ数が証明できる範囲で少なく、高頻度の特定検索パターンがある場合に限り検討する
