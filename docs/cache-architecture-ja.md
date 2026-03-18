> English version: [cache-architecture.md](cache-architecture.md)

# Cache Architecture

## Overview

Kura はリファレンス/マスターデータを APCu にキャッシュし、`ReferenceQueryBuilder` で検索する。
データの読み込みは `LoaderInterface` を通じて行い、Loader の実装は別パッケージとする。

> **本ドキュメントは実装の設計仕様書**。全体構成や利用方法については `overview-ja.md` を参照。
>
> 関連ドキュメント:
> - [バージョン管理](version-management-ja.md) — バージョンドライバー、Middleware、デプロイフロー
> - [インデックスガイド](index-guide-ja.md) — インデックス種類、チャンク分割、composite index、範囲クエリ
> - [クエリレシピ](query-recipes-ja.md) — 実践的なクエリパターンと例

---

## アーキテクチャ

### クラス構成

```
ReferenceQueryBuilderInterface extends BuilderContract
  └─ ReferenceQueryBuilder implements ReferenceQueryBuilderInterface
       │
       │  where(), orderBy(), limit() 等 — Laravel Builder と同じシグネチャ
       │  状態の構築のみ。実行は CacheProcessor に委譲
       │
       └─ CacheProcessor
            │  select(), cursor() — キャッシュからの実行
            │  resolveIds()       — index で候補 IDs を絞り込む
            │  compilePredicate() — where 条件をクロージャに変換
            │
            ├─ CacheRepository
            │    │  find(), ids(), meta(), reload()
            │    │  APCu の読み書き + Self-Healing
            │    │
            │    ├─ StoreInterface（APCu 抽象化）
            │    │    ├─ ApcuStore（本番）
            │    │    └─ ArrayStore（テスト）
            │    │
            │    └─ LoaderInterface（データ読み込み）
            │         └─ 別パッケージで実装（CsvLoader, EloquentLoader 等）
            │
            └─ RecordCursor
                 generator ベースのレコード走査。条件評価は WhereEvaluator に委譲
```

### 設計方針

- **BuilderContract**: `Illuminate\Contracts\Database\Query\Builder` を extends した
  `ReferenceQueryBuilderInterface` を定義。BuilderContract は現在空の interface だが、
  `instanceof BuilderContract` が使える
- **Processor パターン**: Laravel が `Grammar → Connection → Processor` でクエリを実行するのと同様に、
  `ReferenceQueryBuilder → CacheProcessor → CacheRepository` でキャッシュクエリを実行する。
  Grammar や Connection は不要
- **QueryBuilder は状態構築のみ**: where/order/limit の状態を保持し、実行は `CacheProcessor` に委譲。
  index 解決、レコード走査、条件評価は全て Processor 側の責務
- **LoaderInterface は Kura 側に定義**: `load()`, `columns()`, `indexes()` を持つ。
  実装（CSV, DB 等）は別パッケージ

### LoaderInterface

```php
interface LoaderInterface
{
    /** @return Generator<int, array<string, mixed>> */
    public function load(): Generator;

    /** @return array<string, string> カラム名 → 型（'int', 'string', 'float', 'bool'） */
    public function columns(): array;

    /**
     * @return list<array{
     *     columns: list<string>,
     *     unique: bool,
     * }>
     *
     * 例:
     *   [
     *       ['columns' => ['country'], 'unique' => false],
     *       ['columns' => ['email'], 'unique' => true],
     *       ['columns' => ['country', 'category'], 'unique' => false],  // composite
     *   ]
     *
     * composite index の columns 順序:
     *   first = カーディナリティが低いカラム（値の種類が少ない方）
     *   → 各カラムの単カラム index も自動的に作成される
     */
    public function indexes(): array;

    /**
     * キャッシュキーに含まれるバージョン識別子。
     *
     * @return string|int|Stringable
     *
     * 例: 'v1.0.0', 20260313, new SemVer(1, 0, 0)
     */
    public function version(): string|int|\Stringable;
}
```

- `load()`: generator で省メモリ。DB なら paginate 相当で chunk 読み
- `columns()`: カラム名と型の定義（`'int'`, `'string'`, `'float'`, `'bool'`）。meta 構築に使用
- `indexes()`: 単カラム・composite index の宣言。Loader 側の責務
  - `unique: true` → unique index（単一 ID 返却）
  - `unique: false` → non-unique index（ID リスト返却）
  - composite index は `columns` にカラムを順序付きで指定。各カラムの単カラム index も自動作成
- `version()`: キャッシュキーに含まれるバージョン識別子。`string|int|Stringable` を返す
  - データソースのバージョンを Loader 側が管理する（CSV ファイル名、DB タイムスタンプ等）
  - version が変わるとキャッシュキーが変わり、旧キャッシュは自然に TTL 消滅する

---

## キャッシュの種類

APCu に保存するデータは **5種類**。

| 種類 | 役割 | 消失時の動作 |
|------|------|-------------|
| **meta** | カラム定義 + index 構造 + composites | 全再構築 |
| **ids** | 全 ID のリスト | 全再構築 |
| **record** | 1レコードのデータ（連想配列） | ids で存在チェック → あるべきなら全再構築 |
| **index** | 検索用インデックス（ID リスト） | full scan で応答 + 全キャッシュ再構築 |
| **cidx** | composite index（複合カラム hashmap） | full scan で応答 + 全キャッシュ再構築 |

---

## 1. meta

テーブルのメタ情報。カラム定義と index 構造を保持する。

```php
kura:products:v1.0.0:meta → [
    'columns' => [
        'id'      => 'int',
        'name'    => 'string',
        'country' => 'string',
        'price'   => 'int',
    ],
    'indexes' => [
        // chunk なし（デフォルト）
        'country' => [],

        // chunk あり（config で chunk_size 指定時）
        'price' => [
            ['min' => 100,  'max' => 500],
            ['min' => 501,  'max' => 1000],
            ['min' => 1001, 'max' => 3000],
        ],
    ],
    'composites' => ['country|category'],
]
```

### 役割

- **columns**: カラム名と型の定義。index 構築時の型判定に使用
- **indexes**: どのカラムに index があり、chunk がどう分割されているか
  - `[]`（空配列）→ chunk なし。index は単一キー
  - 配列あり → chunk あり。各要素の min/max で範囲を表す
- **composites**: composite index 名のリスト（`"col1|col2"` 形式）

### 特性

- meta が消えたら → **全再構築**

---

## 2. ids

全 ID のリスト。

```php
kura:products:v1.0.0:ids → [1, 2, 3, ...]
```

### 役割

- 全件走査時の候補 ID セット
- record 欠損時に「本当にあるべきデータか」を判定する基準
- intersection が必要な場合は `array_flip` で hashmap に変換

### 特性

- ids が消えたら → **全再構築**
- TTL は 5種類の中で最短（再構築トリガーの役割）

---

## 3. record

1レコードのデータ。連想配列でそのまま保持する。

```php
kura:products:v1.0.0:record:1 → ['id' => 1, 'name' => 'Widget A', 'country' => 'JP', 'price' => 500]
```

- record 単体で自己完結（meta なしで読める）
- `find(id)` が最も頻度の高い操作 → meta 不要で即返却できる
- meta は index 構造の管理に専念

### record 欠損時の Self-Healing

```
record 取得
  └─ ヒット → 正常応答
  └─ ミス
       └─ ids[id] が存在する → あるべきデータが消えた → 全再構築
       └─ ids[id] が存在しない → 本当にないデータ → null 返却
```

---

## 4. index

検索用インデックス。カラムの値から ID を引くための構造（単カラム）。

### chunk なし（デフォルト）

各値ごとに 1 キー。value → IDs のマッピング。

```php
kura:products:v1.0.0:idx:country → [
    ['JP', [1, 3, 6]],
    ['US', [2, 4, 8]],
    ['DE', [5, 7]],
]
// value 昇順ソート済み
```

- 等価検索 `=` → binary search で O(log n)
- 範囲検索 `>`, `<`, `BETWEEN` → binary search で開始位置特定 → slice

### chunk あり（config で chunk_size 指定時）

大量データ向け。index をユニーク値の数で chunk_size 件ごとに分割し、各 chunk の min/max を meta に保持。
chunk_size の単位は **ユニーク値の数**（1 chunk に含まれる異なる value の数）。

```php
// meta 内の定義
'price' => [
    ['min' => 100,  'max' => 500],    // chunk 0
    ['min' => 501,  'max' => 1000],   // chunk 1
    ['min' => 1001, 'max' => 3000],   // chunk 2
]

// 各 chunk キー
kura:products:v1.0.0:idx:price:0 → [
    [100, [3, 7]],       // price=100 の IDs
    [200, [1, 12]],      // price=200 の IDs
    [500, [6, 9, 15]],   // price=500 の IDs
]
kura:products:v1.0.0:idx:price:1 → [
    [501, [2, 5]],
    [700, [8, 14]],
    [1000, [4, 11]],
]
```

- chunk 内部も `[[value, [ids]], ...]` 構造（value 昇順ソート済み）
- 等価・範囲の両方で record fetch なしに ID を解決できる
- **同一値は必ず同じ chunk に収まる**（chunk 境界をまたがない）

#### chunk 分割アルゴリズム

```
1. index 用データ [value → [ids]] を value 昇順でソート
2. ソート済みリストを chunk_size 件（ユニーク値の数）ずつ slice
3. 各 chunk の先頭値 = min、末尾値 = max として meta に記録
```

### index のクエリ時の動き

```
where('price', '=', 700)
  └─ meta 参照 → chunk 1 (501〜1000) にヒット
       └─ chunk 1 内で binary search → [8, 14] を即取得

where('price', '>', 800)
  └─ meta 参照 → chunk 1 + chunk 2 がオーバーラップ
       └─ 各 chunk 内で binary search → 該当 IDs を収集

where('price', 'BETWEEN', [200, 600])
  └─ meta 参照 → chunk 0 + chunk 1 がオーバーラップ
       └─ 各 chunk 内で範囲 slice → 該当 IDs を収集
```

### 複数カラムの WHERE（intersection）

```
where('country', 'JP')->where('price', '>', 500)
  └─ country index → [1, 3, 6, ...]
  └─ price index   → [2, 3, 8, ...]
  └─ array_flip → hashmap 化 → array_intersect_key → [3]
  └─ record fetch → フィルタ
```

index の返り値は ID リスト `[id, ...]`。intersection 時は `array_flip` で hashmap に変換し `array_intersect_key` で高速化する。

### index の宣言

index の定義は **Loader 側の責務**。`LoaderInterface::indexes()` でデータと一緒に提供する。
CSV なら defines.csv や indexes.csv から読み取る。DB なら schema から取得。
Kura 側は `LoaderInterface` 経由で受け取るだけ。

### composite index

単カラム index も composite index も Loader の indexes() で宣言する。

```php
// LoaderInterface::indexes() の戻り値例
[
    ['columns' => ['country'],            'unique' => false],
    ['columns' => ['email'],              'unique' => true],
    ['columns' => ['country', 'category'],'unique' => false],  // composite
]
```

composite index の first column には **1値あたりの件数が多いカラム**
（カーディナリティが低い方）を選ぶ。上記の例では `country`（JP, US, DE 等）が
`category` よりカーディナリティが低いため first column にする。

Kura は composite index 宣言時に各カラムの **単カラム index も自動的に作成** する。
WHERE の順序や一方のカラムだけの条件にも対応するため。
Loader 側で単カラム index を重複して宣言する必要はない。

上記の例では Kura が自動的に以下の index を構築する:
- `idx:country` — 明示宣言
- `idx:email` — 明示宣言（unique）
- `idx:category` — composite `['country', 'category']` から自動生成
- composite: `country → category` の階層 index

### index が使える条件

```
=        → binary search で完全一致
>, <     → binary search で開始/終了位置特定 → slice
>=, <=   → 同上
BETWEEN  → binary search で範囲 slice
AND      → 各 index の結果を intersection（array_intersect_key）
OR       → 全条件が index ヒット → union（array + array_unique）
           → 1つでも index なし → index 解決を諦め ids 全件で full scan
ROW IN   → composite index があれば hashmap lookup で O(1) per tuple
           composite index なし or NOT IN → full scan
```

### 複合条件の否定（De Morgan の法則）

`whereNone` / `orWhereNone` は内部で De Morgan の法則を適用する。

```
whereNone(['name', 'email'], '=', 'alice@example.com')

内部変換:
  NOT (name = 'alice@example.com' OR email = 'alice@example.com')

De Morgan の法則により:
  (name != 'alice@example.com') AND (email != 'alice@example.com')
```

実装では OR 結合したネスト条件を `negate` フラグで否定する。
De Morgan の展開は compilePredicate() 内のクロージャ評価で暗黙的に適用される。

### index 消失時

- 他のキャッシュ欠損と同じフロー（Self-Healing まとめ参照）
- 同期パスは index なしで full scan しつつ結果を返す
- Queue dispatch で全キャッシュ（index 含む）を再構築

---

## 5. composite index (cidx)

複合カラムの AND equality を O(1) で解決するための hashmap。

```php
kura:products:v1.0.0:cidx:country|category → [
    'JP|electronics' => [1, 3],
    'JP|food'        => [6],
    'US|electronics' => [2, 4],
    'US|food'        => [8],
]
```

### 構造

- キー: `{val1|val2}` の文字列結合
- 値: ID リスト `[id, ...]`
- hashmap なので lookup は O(1)

### 用途

- **AND equality**: `where('country', 'JP')->where('category', 'electronics')` → `IndexResolver::tryCompositeIndex()` が単一 APCu fetch で解決
- **ROW constructor IN**: `whereRowValuesIn(['country', 'category'], [['JP', 'electronics'], ...])` → `IndexResolver::resolveRowValuesIn()` が各タプルを O(1) で lookup

### 構築

`IndexBuilder::buildCompositeIndexes()` が rebuild 時に構築。
2カラム以上の index 宣言から自動生成される。NULL を含む値の組み合わせはスキップ。

---

### ROW constructor IN（Kura 拡張）

MySQL の ROW constructor 構文に対応する Kura 独自拡張。
Laravel の `Query\Builder` には存在しない（`whereRaw()` が必要）。

```php
// MySQL: SELECT * FROM t WHERE (user_id, item_id) IN ((1, 10), (2, 20))
$builder->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [2, 20]]);

// NOT IN
$builder->whereRowValuesNotIn(['user_id', 'item_id'], [[1, 10]]);

// OR variants
$builder->orWhereRowValuesIn(['user_id', 'item_id'], [[1, 10]]);
$builder->orWhereRowValuesNotIn(['user_id', 'item_id'], [[1, 10]]);
```

**内部実装:**
- where type: `rowValuesIn`
- `resolveSubqueries()` で `tupleSet` hashmap を構築: `"1|10" => true`（値を `|` 結合した文字列キー）
- RecordCursor で O(1) マッチング
- composite index がある場合、IndexResolver が直接 ID を解決（full scan 不要）
- NOT IN は composite index で加速できない（full scan にフォールバック）

**NULL 処理（MySQL 準拠）:**
- カラム値に NULL がある場合、IN/NOT IN ともに false（NULL 伝播）

---

### NULL の扱い（MySQL 準拠）

Kura は MySQL のセマンティクスに従い、NULL が含まれる比較は false を返す。

| 操作 | NULL の動作 |
|------|-----------|
| `=`, `!=`, `<>` | strict comparison（`null === null` は true） |
| `>`, `>=`, `<`, `<=` | NULL → false |
| `IN` / `NOT IN` | カラム値が NULL → 常に false |
| `BETWEEN` / `NOT BETWEEN` | NULL → false（NOT → true） |
| `LIKE` / `NOT LIKE` | NULL → false |
| ROW constructor IN/NOT IN | カラム値に NULL → 常に false |
| `ORDER BY` | NULL は最小値（ASC: 先頭、DESC: 末尾） |

---

## データ取得 → キャッシュ構築の流れ

### クエリ時のキャッシュ判定

```
クエリ実行
  │
  ├─ ロックあり（rebuild 中）
  │    → キャッシュを見ない
  │    → Loader→generator → where 評価 → 返却
  │
  ├─ ロックなし + ids あり + meta あり
  │    → 通常クエリ（index 活用）
  │
  ├─ ロックなし + ids あり + meta なし
  │    → ids + full scan（index 構築中 or index 消失）
  │    → Queue dispatch で index + meta 再構築
  │
  └─ ロックなし + ids なし
       → Queue dispatch で全キャッシュ再構築
       → Loader→generator → where 評価 → 返却
```

- rebuild 中はキャッシュの整合性が保証できないため、Loader 直撃で応答
- Loader は generator で省メモリ（DB なら paginate 相当で chunk 読み）

### Rebuild Job

**2段階でキャッシュを構築する。** record + ids が先に完成し、クエリ可能になる。

```
Phase 1（APCu ロック中）:
  Loader->load() で generator 取得
  1 回のループで:
    ├─ record を apcu_store（1件ずつ）
    ├─ ids を収集 [id, ...]
    └─ index 用データを収集 [col → [value → [id, ...]]]
  ループ後:
    └─ ids を apcu_store
  ロック解除 ← ここでクエリ可能（full scan モード）

Phase 2（ロックなし）:
  index を構築（ソート + chunk 分割）→ apcu_store
  meta を構築 → apcu_store
  ← ここで index 活用クエリが可能になる
```

- **Phase 1**: record + ids を構築。ロック中は全クエリが Loader 直撃
- **Phase 2**: index + meta を構築。ロック解除済みなのでクエリは full scan で応答可能
- record は即座に参照可能にするためループ中に 1 件ずつ `apcu_store`
- ids はループ後に一括 `apcu_store`

apcu_store は上書き。既存データがあっても同じデータで上書きされ TTL がリセット（延長）される。
存在チェックは不要。Loader から全件書き直すだけ。

### クエリ実行フロー（CacheProcessor）

cursor() は generator で 1 件ずつ返す。get() は内部で cursor() を使い配列で返す。
record 欠損時は cursor() が例外、get() は catch して Loader フォールバック。

```
where('country', 'JP')->where('price', '>', 500)->get()

⓪ ロック判定 + ids/meta 存在チェック
   ├─ ロックあり → Loader 直撃
   ├─ ids なし → Loader 直撃 + rebuild dispatch
   └─ ids あり → キャッシュクエリ続行

① 候補 IDs の解決（resolveIds）
   meta あり → index で絞れる条件があるか判定
     ├─ あり → index から ID セット取得（intersection / union）
     └─ なし → ids（全件）
   meta なし → ids 全件（full scan）+ rebuild dispatch

② 全 where 条件をクロージャに変換（compilePredicate）

③ 候補 IDs をループ
   foreach ($candidateIds as $id)
     ├─ record 取得
     │    └─ record 欠損 + ids にある → Loader フォールバック（後述）
     ├─ クロージャで全 where 条件を評価
     │    ├─ マッチ → 結果配列に追加
     │    └─ 不マッチ → skip
     └─ （index ヒット分も含め、必ず全条件を再評価する）

④ order / limit / offset 適用

⑤ 結果返却（配列）
```

**index は「候補の絞り込み」だけ。最終判定は必ずクロージャ。**
index あり/なしで分岐するのは resolveIds() だけ。ループと判定ロジックは共通。

---

## Self-Healing まとめ

**呼び出し元には常に完全な結果を返す。** データの欠損は絶対に露出しない。

### 欠損の種類と対応

```
クエリ実行
  │
  ├─ ロックあり（rebuild 中）
  │    → Loader 直撃で応答
  │
  ├─ ids あり + meta あり → 通常クエリ（index 活用）
  │
  ├─ ids あり + meta なし → full scan で応答 + Queue dispatch で index/meta 再構築
  │
  ├─ ids なし → Loader 直撃で応答 + Queue dispatch で全再構築
  │
  ├─ record 欠損（ループ途中で検知。極めてまれ）
  │    → cursor(): CacheInconsistencyException + Queue dispatch
  │    → get(): 例外を catch → Loader フォールバック
  │
  └─ index 消失（meta はあるが index キーが消えた）
       → full scan で応答 + Queue dispatch で index 再構築
```

### cursor() と get()

```
cursor(): generator で 1 件ずつ返す
  正常時 → そのまま yield（99.99%）
  record 欠損 → CacheInconsistencyException + Queue dispatch

get(): 配列で返す
  内部で cursor() を使用
  CacheInconsistencyException → catch → Loader フォールバック
```

record 欠損は APCu の異常事態（メモリ逼迫、プロセス再起動等）でのみ発生。
正常運用ではリファレンスデータは全件 APCu に載る。

**cursor() と get() で例外動作が異なる理由:**
cursor() は generator なので、途中で Loader に切り替えると重複レコードが発生する。
get() は配列を返す完結型なので、例外を catch して Loader から取り直せる。

### 運用上の注意

record 欠損は APCu メモリ逼迫による eviction でのみ発生する。
Self-Healing は安全弁であり、頻発する場合はインフラ側の問題として対処する:

- `apc.shm_size` の増加
- APCu メモリ使用量の監視（apc.php, Prometheus exporter 等）
- キャッシュ対象テーブルの見直し（不要なテーブルを除外）
- blob や大きな JSON カラムはキャッシュ対象から除外する

### apc.shm_size の見積もり

```
必要メモリ ≒ Σ（1レコードあたりのサイズ × レコード数 × 2〜3倍）

内訳:
  record:  serialize(連想配列) × レコード数
  ids:     serialize 後 約 8〜12 bytes/entry（[id, ...] リスト形式）
  index:   [[value, [ids]], ...] × カラム数
  meta:    数 KB（無視できる）

× 2〜3倍: APCu 内部のオーバーヘッド（ハッシュテーブル、メモリフラグメンテーション）
```

目安:
- 1レコード 200 bytes × 50,000件 → record だけで約 10MB
- index・ids・オーバーヘッド込みで **30〜50MB** 程度
- 複数テーブルをキャッシュする場合は合算
- `apcu_cache_info('user')` で実際の使用量を確認し、使用率 80% 以下を維持

### エラーハンドリング

Kura はキャッシュ欠損の復旧は行うが、Loader 自体の障害は握りつぶさない。

- Loader 接続エラー（DB 接続失敗、CSV ファイル不在等）→ 例外をそのまま throw
- APCu 書き込み失敗 → 例外を throw（apc.shm_size 不足等）
- Self-Healing 中の Loader エラー → 同上。呼び出し元に伝播

Kura の責務は「キャッシュがあれば高速に返す、なければ Loader に委譲する」まで。
Loader の可用性は Loader 側・インフラ側の責務。

### 実装イメージ

```php
// CacheProcessor::cursor()
public function cursor(Builder $builder): Generator
{
    $meta = $repository->meta();
    $ids = $repository->ids();

    // ロック中 → キャッシュの整合性が保証できない
    if ($repository->isLocked()) {
        yield from $this->cursorFromLoader($builder);
        return;
    }

    if ($ids === false) {
        // ids なし → Loader 直撃 + 全再構築 dispatch
        $this->dispatchRebuild($table, $version);
        yield from $this->cursorFromLoader($builder);
        return;
    }

    // meta なし → index 使えない。full scan + index/meta 再構築 dispatch
    if ($meta === false) {
        $this->dispatchRebuild($table, $version);
    }

    // meta あり → index で絞り込み、meta なし → ids 全件
    $candidateIds = $meta !== false
        ? $this->resolveIds($builder, $ids, $meta)
        : $ids;
    $predicate = $this->compilePredicate($builder);

    $idsMap = array_fill_keys($ids, true);

    foreach ($candidateIds as $id) {
        $record = $repository->find($id);

        if ($record === null && isset($idsMap[$id])) {
            $this->dispatchRebuild($table, $version);
            throw new CacheInconsistencyException("Record {$id} missing");
        }

        if ($record !== null && $predicate($record)) {
            yield $record;
        }
    }
}

// CacheProcessor::select() — get() から呼ばれる
public function select(Builder $builder): array
{
    try {
        return iterator_to_array($this->cursor($builder));
    } catch (CacheInconsistencyException) {
        return $this->selectFromLoader($builder);
    }
}
```

### Rebuild の重複防止

`apcu_add` でロックキーを取得し、同時に複数の rebuild が走ることを防ぐ。

```
{prefix}:{table}:lock → apcu_add（TTL は config で設定可能。デフォルト 60秒）
```

- ロック取得成功 → rebuild 実行
- ロック取得失敗 → 他のプロセスが rebuild 中。Loader フォールバックで応答のみ
- rebuild 完了 → ロックは TTL で自動消滅（明示削除しない = 安全側に倒す）

※ `apcu_add` はロック用途にのみ使用。データの書き込みは `apcu_store` 統一。

### Queue Job の原則

**「あれば TTL 延長、なければ作る」**

- APCu にある → 読み込んで再保存（`apcu_store` で TTL リセット）
- APCu にない → Loader からデータ取得して構築・保存
- 全部消えていても、一部だけ消えていても、同じロジックで動く
- Loader を呼んだら全キャッシュ（ids, record, meta, index）を再構築する

**ids のみ消失の場合**: meta/record/index は生きているので、Loader から ids だけ再構築し、
既存キャッシュは `apcu_store` で TTL をリセットする。全件再ロードは不要。

### Rebuild Strategy

rebuild dispatcher は `Closure(CacheRepository): void` として `CacheProcessor` に注入される。
`null` の場合は同期実行。`config/kura.php` で設定し、`KuraServiceProvider` が配線する。

---

#### strategy: sync（デフォルト）

```php
'rebuild' => ['strategy' => 'sync'],
```

```
get() / first() — キャッシュミス検知
  │
  ├─ Loader から返却（Generator → レコードを呼び出し元に返す）
  └─ 同じプロセス・同じリクエスト内で rebuild() を実行
       └─ Phase 1: 全件ロード → record + ids を APCu に書き込み（ロック中）
       └─ Phase 2: index + meta を構築 → APCu に書き込み（ロック解除後）
       └─ 次のリクエストから APCu から通常返却

レイテンシ: 初回ミス = Loader 読み込み時間 + キャッシュ書き込み時間
Queue:      不要
利用シーン: 開発、小規模データ、Queue なし環境
```

---

#### strategy: queue ⭐ 本番推奨

```php
'rebuild' => [
    'strategy' => 'queue',
    'queue' => [
        'connection' => null,   // null = デフォルト接続
        'queue'      => null,   // null = デフォルトキュー
        'retry'      => 3,
    ],
],
```

```
get() / first() — キャッシュミス検知
  │
  ├─ dispatch(RebuildCacheJob)  ← 非同期、即時返却
  └─ Loader から返却            ← 現在のリクエストはすぐに応答

  [バックグラウンドワーカー]
    RebuildCacheJob::handle()
      └─ KuraManager::rebuild($table)
           └─ Phase 1: ロード → APCu（ロック中）
           └─ Phase 2: index + meta → APCu（ロック解除後）

  次のリクエスト → APCu ヒット（通常の高速パス）

レイテンシ: 初回ミス = Loader 読み込み時間のみ（キャッシュ書き込みのオーバーヘッドなし）
Queue:      Laravel Queue 必須（Redis / SQS / database 等）
利用シーン: 本番環境 — キャッシュミスが呼び出し元に透過的
```

---

#### strategy: callback（カスタム dispatcher）

Horizon / カスタムジョブ / Octane タスクなど、任意のディスパッチロジックを使える。
`Closure(CacheRepository): void` を `KuraServiceProvider` をオーバーライドして登録する:

```php
// app/Providers/AppServiceProvider.php
use Kura\CacheRepository;
use Kura\KuraManager;

public function register(): void
{
    $this->app->extend(KuraManager::class, function (KuraManager $manager) {
        $manager->setRebuildDispatcher(function (CacheRepository $repo): void {
            // 例: Horizon の特定キューに dispatch
            MyCustomRebuildJob::dispatch($repo->table())->onQueue('kura-rebuild');
        });
        return $manager;
    });
}
```

```
get() / first() — キャッシュミス検知
  │
  ├─ ($yourClosure)($repository)  ← 独自ロジックをここで実行
  └─ Loader から返却

レイテンシ: クロージャの実装次第
Queue:      任意
利用シーン: Horizon 優先キュー / Octane / カスタムテレメトリ等
```

---

#### 比較

| strategy | Queue 必要 | ミス時レイテンシ | 利用シーン |
|---|---|---|---|
| **sync** | 不要 | Loader + 再構築時間 | 開発 / 小規模 / Queue なし |
| **queue** | 必要（Laravel Queue） | Loader のみ | 本番（推奨） |
| **callback** | 任意 | 実装次第 | カスタムインフラ |

---

## Warm エンドポイント

`POST /kura/warm` は登録済みテーブル（または指定したテーブル）の APCu キャッシュを再構築する。
デプロイ後にトラフィックが来る前に事前ウォームアップするのに使う。

`config/kura.php` で有効化:

```php
'warm' => [
    'enabled' => true,
    'token'   => env('KURA_WARM_TOKEN', ''),  // Bearer トークン（必須）
    'path'    => 'kura/warm',                  // URL パス
],
```

### リクエスト

```
POST /kura/warm
Authorization: Bearer {KURA_WARM_TOKEN}

クエリパラメーター:
  tables  — カンマ区切りのテーブル名（省略 = 全登録テーブル）
  version — バージョンオーバーライド（例: v2.0.0）
```

### Strategy 別の挙動

#### strategy: sync

全テーブルを同一リクエスト内で直列 rebuild。全完了後に返す。

```
POST /kura/warm
  │
  ├─ rebuild stations  ┐
  ├─ rebuild lines     ├ 直列、同一リクエスト内
  └─ rebuild products  ┘
  │
  └─ 200 OK
     {
       "message": "All tables warmed.",
       "tables": {
         "stations": {"status": "ok", "version": "v1.0.0"},
         "lines":    {"status": "ok", "version": "v1.0.0"}
       }
     }
```

#### strategy: queue ⭐ 本番推奨

テーブルごとに `RebuildCacheJob` を **Bus batch** として dispatch。即時返却（202）。
Queue ワーカーがテーブルを並列処理する。

```
POST /kura/warm
  │
  ├─ Bus::batch([StationsJob, LinesJob, ProductsJob])->dispatch()
  └─ 202 Accepted（即時返却）
     {
       "message": "Rebuild dispatched.",
       "batch_id": "550e8400-e29b-41d4-a716-446655440000",
       "tables": {
         "stations": {"status": "dispatched", "version": "v1.0.0"},
         "lines":    {"status": "dispatched", "version": "v1.0.0"}
       }
     }

  [Queue ワーカー — 並列]
    Worker 1: RebuildCacheJob(stations) → rebuild
    Worker 2: RebuildCacheJob(lines)    → rebuild
    Worker 3: RebuildCacheJob(products) → rebuild
```

### batch_id について

`batch_id` は `Bus::batch()->dispatch()` 時に Laravel が自動生成する UUID。
`job_batches` テーブルに保存されており、進捗確認が可能:

```php
$batch = Bus::findBatch($batchId);
$batch->totalJobs;    // 全ジョブ数
$batch->pendingJobs;  // 残り
$batch->failedJobs;   // 失敗数
$batch->finished();   // bool
```

**必要なマイグレーション**（`strategy: queue` を使う場合のみ）:

```bash
php artisan queue:batches-table
php artisan migrate
```

このマイグレーションがないと `Bus::batch()->dispatch()` がエラーになる。

---

## TTL

```php
'ttl' => [
    'ids'        => 3600,    // 1時間（最短。再構築トリガー）
    'meta'       => 4800,    // 1時間20分
    'record'     => 4800,    // 1時間20分
    'index'      => 4800,    // 1時間20分
    'ids_jitter' => 600,     // ids TTL に加算するランダム値（0〜600秒）スパイク防止
],
```

### 関係

```
ids (3600) < meta / record / index / cidx (4800)
```

- **ids が最初に消える** → 再構築トリガー
- **meta はまだ生きている** → index 構造がわかるのでクエリ最適化が可能
- **record/index もまだ生きている** → 再構築中もクエリに応答可能

### 書き込みルール

**全キー `apcu_store` で統一。**

- `apcu_store` は現在時刻 + TTL で有効期限をセット。再 store するたびに期限がリセット（実質延長）される
- 消し飛んだデータを作り直すと同時に TTL を伸ばす
- `apcu_add` は使わない

---

## Config

```php
// config/kura.php
return [
    'prefix' => 'kura',

    'ttl' => [
        'ids'    => 3600,
        'meta'   => 4800,
        'record' => 4800,
        'index'  => 4800,
    ],

    'chunk_size' => null,  // null = chunk しない。10000 等で全テーブル共通 chunk

    'lock_ttl' => 60,  // rebuild ロックの TTL（秒）。Loader 実行時間の 1.5〜2倍を目安に設定

    'tables' => [
        // テーブル単位でオーバーライドしたい場合のみ
        // 'products' => [
        //     'ttl' => ['record' => 7200],
        //     'chunk_size' => 10000,
        // ],
    ],
];
```

---

## キー構造

```
{prefix}:{table}:{version}:meta                    — メタ情報（columns + indexes + composites）
{prefix}:{table}:{version}:ids                     — 全 ID リスト [id, ...]
{prefix}:{table}:{version}:record:{id}             — 1レコード（連想配列）
{prefix}:{table}:{version}:idx:{col}               — index（chunk なし、単一キー）
{prefix}:{table}:{version}:idx:{col}:{chunk}       — index（chunk あり、chunk 番号）
{prefix}:{table}:{version}:cidx:{col1|col2}        — composite index（hashmap）
{prefix}:{table}:lock                               — rebuild ロック（version 非依存）
```

デフォルト（prefix=`kura`）:
```
kura:products:v1.0.0:meta
kura:products:v1.0.0:ids
kura:products:v1.0.0:record:1
kura:products:v1.0.0:idx:country              — chunk なし
kura:products:v1.0.0:idx:price:0              — chunk あり
kura:products:v1.0.0:idx:price:1
kura:products:v1.0.0:cidx:country|category    — composite index
```
