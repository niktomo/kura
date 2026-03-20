> English version: [cache-architecture.md](cache-architecture.md)

# Cache Architecture

## Overview

Kura はリファレンス/マスターデータを APCu にキャッシュし、`ReferenceQueryBuilder` で検索する。
データの読み込みは `LoaderInterface` を通じて行う。`CsvLoader`、`EloquentLoader`、`QueryBuilderLoader` はいずれも `src/Loader/` に含まれる。

> **本ドキュメントは実装の設計仕様書**。全体構成や利用方法については `overview-ja.md` を参照。
>
> 関連ドキュメント:
> - [バージョン管理](version-management-ja.md) — バージョンドライバー、Middleware、デプロイフロー
> - [インデックスガイド](index-guide-ja.md) — インデックス種類、composite index、範囲クエリ
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
            │    │  find(), ids(), reload()
            │    │  APCu の読み書き + Self-Healing
            │    │
            │    ├─ StoreInterface（APCu 抽象化）
            │    │    ├─ ApcuStore（本番）
            │    │    └─ ArrayStore（テスト）
            │    │
            │    └─ LoaderInterface（データ読み込み）
            │         └─ CsvLoader / EloquentLoader / QueryBuilderLoader（src/Loader/）
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
  `CsvLoader`、`EloquentLoader`、`QueryBuilderLoader` は `src/Loader/` に含まれる

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

- `load()`: generator で省メモリ。DB なら paginate 相当で読み込み
- `columns()`: カラム名と型の定義（`'int'`, `'string'`, `'float'`, `'bool'`）。クエリ時の型判定に使用
- `indexes()`: 単カラム・composite index の宣言。Loader 側の責務
  - `unique: true` / `unique: false` — ドキュメント用ヒントのみ。Kura はユニーク性を強制せず、どちらも ID をリストで保持し、クエリ時の動作は同一
  - composite index は `columns` にカラムを順序付きで指定。各カラムの単カラム index も自動作成
- `version()`: キャッシュキーに含まれるバージョン識別子。`string|int|Stringable` を返す
  - データソースのバージョンを Loader 側が管理する（CSV ファイル名、DB タイムスタンプ等）
  - version が変わるとキャッシュキーが変わり、旧キャッシュは自然に TTL 消滅する

---

## キャッシュの種類

APCu に保存するデータは **4種類**。

| 種類 | 役割 | 消失時の動作 |
|------|------|-------------|
| **ids** | 全 ID のリスト | 全再構築 |
| **record** | 1レコードのデータ（連想配列） | ids で存在チェック → あるべきなら全再構築 |
| **index** | 検索用インデックス（ID リスト） | full scan で応答 + 全キャッシュ再構築 |
| **cidx** | composite index（複合カラム hashmap） | full scan で応答 + 全キャッシュ再構築 |

インデックス構造（どのカラムがインデックスされているか、composite の一覧）は **APCu には保存しない**。
クエリ時に `LoaderInterface::indexes()` から取得する（Loader がインスタンスキャッシュ）。

---

## 1. ids

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
- TTL は 4種類の中で最短（再構築トリガーの役割）

---

## 2. record

1レコードのデータ。連想配列でそのまま保持する。

```php
kura:products:v1.0.0:record:1 → ['id' => 1, 'name' => 'Widget A', 'country' => 'JP', 'price' => 500]
```

- record 単体で自己完結（meta への依存なし）
- `find(id)` が最も頻度の高い操作 → 即返却できる

### record 欠損時の Self-Healing

```
record 取得
  └─ ヒット → 正常応答
  └─ ミス
       └─ ids[id] が存在する → あるべきデータが消えた → 全再構築
       └─ ids[id] が存在しない → 本当にないデータ → null 返却
```

---

## 3. index

検索用インデックス。カラムの値から ID を引くための構造（単カラム）。

カラムごとに 1 キー。value → IDs のマッピング。value 昇順ソート済み。

```php
kura:products:v1.0.0:idx:country → [
    ['JP', [1, 3, 6]],
    ['US', [2, 4, 8]],
    ['DE', [5, 7]],
]
```

- 等価検索 `=` → binary search で O(log n)
- 範囲検索 `>`, `<`, `BETWEEN` → binary search で開始位置特定 → slice

### index のクエリ時の動き

```
where('price', '=', 700)
  └─ binary search → [8, 14] を即取得

where('price', '>', 800)
  └─ binary search で開始位置特定 → 末尾まで slice → ID を収集

where('price', 'BETWEEN', [200, 600])
  └─ binary search で範囲の境界を特定 → slice → ID を収集
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

## 4. composite index (cidx)

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
  ├─ ロックなし + ids あり
  │    → 通常クエリ（Loader::indexes() から取得したインデックス構造を使用）
  │
  └─ ロックなし + ids なし
       → Queue dispatch で全キャッシュ再構築
       → Loader→generator → where 評価 → 返却
```

- rebuild 中はキャッシュの整合性が保証できないため、Loader 直撃で応答
- Loader は generator で省メモリ（DB なら paginate 相当で読み込み）

### Rebuild Job

**キャッシュは rebuild ロック中に全件構築される。**

```
（APCu ロック中、全体を通じて）:
  flush() — 対象テーブル+バージョンの全既存キーを削除

  Loader->load() で generator 取得
  1 回のループで:
    ├─ record を apcu_store（1件ずつ）
    ├─ ids を収集 [id, ...]
    └─ index 用データを収集 [col → [value → [id, ...]]]
  ループ後:
    ├─ ids を apcu_store
    ├─ indexes を apcu_store（value 昇順ソート済み）
    └─ composite indexes を apcu_store（hashmap）

  ロック解除 ← ここで index 活用クエリが可能になる
```

- ロック中は全クエリが Loader 直撃（安全、正確）
- index 書き込みはロック内で実施 — ids はあるが index がないウィンドウは存在しない
- record はループ中に 1 件ずつ `apcu_store`
- ids、index、cidx はループ後に一括書き込み

apcu_store は上書き。既存データがあっても同じデータで上書きされ TTL がリセット（延長）される。
存在チェックは不要。Loader から全件書き直すだけ。

### クエリ実行フロー（CacheProcessor）

cursor() は generator で 1 件ずつ返す。get() は内部で cursor() を使い配列で返す。
record 欠損時は cursor() が例外、get() は catch して Loader フォールバック。

```
where('country', 'JP')->where('price', '>', 500)->get()

⓪ ロック判定 + ids 存在チェック
   ├─ ロックあり → Loader 直撃
   ├─ ids なし → Loader 直撃 + rebuild dispatch
   └─ ids あり → キャッシュクエリ続行

① 候補 IDs の解決（resolveIds）
   Loader::indexes() からインデックス構造を取得（インスタンスキャッシュ）
     ├─ 条件がインデックス付きカラムと一致 → index から ID セット取得（intersection / union）
     └─ 一致なし → ids（全件）

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
  ├─ ids あり → 通常クエリ（Loader::indexes() からインデックス構造を取得）
  │
  ├─ ids なし → Loader 直撃で応答 + Queue dispatch で全再構築
  │
  ├─ record 欠損（ループ途中で検知。極めてまれ）
  │    → cursor(): CacheInconsistencyException + Queue dispatch
  │    → get(): 例外を catch → Loader フォールバック
  │
  └─ index 消失（Loader::indexes() で宣言済みだが APCu キーが欠損）
       → IndexInconsistencyException → get(): Loader フォールバック + Queue dispatch で全再構築
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

### 推奨スケール

Kura は **APCu に全件収まる参照データ** を対象としている。読み取り中心で変更頻度が低く、
DB や CSV から数秒〜数分でリビルドできるデータに適している。

| テーブルあたりのレコード数 | APCu 目安 | 備考 |
|---|---|---|
| 〜1万件 | 〜10 MB | 問題なし |
| 1万〜10万件 | 10〜100 MB | 快適な動作範囲 ✅ |
| 10万〜50万件 | 100〜500 MB | `apc.shm_size` の調整が必要・ids 負荷に注意 |
| 50万件超 | 500 MB 超 | 非推奨（下記参照） |

**推奨上限: テーブルあたり約 10万件。**

#### 大規模データで ids がボトルネックになる理由

クエリのたびに ids リスト全件を APCu から取得し、PHP の hashmap を生成する:

```php
$ids    = apcu_fetch('kura:products:v1:ids');  // N 件をデシリアライズ
$idsMap = array_fill_keys($ids, true);          // もう N 件の hashmap を生成
```

100万件の場合、index で候補が数件に絞れていても、**この2行だけで 80〜160 MB** を
リクエストごとに確保することになる。

#### 大規模データへの対応策

- **テーブルを分割する** — カテゴリ・ステータス・地域などで分割し、各テーブルを 10万件以内に収める
- **Loader でフィルタする** — 全件ではなくアクティブな一部のみをロードする
- **大きいカラムを除外する** — blob・大きな JSON・自由入力テキストはキャッシュ対象から外す
- **別のツールを検討する** — 変更頻度が高い、または 50万件を超える場合は Redis バックの
  read model や DB のマテリアライズドビューが適切な場合がある

### apc.shm_size の見積もり

```
必要メモリ ≒ Σ（1レコードあたりのサイズ × レコード数 × 2〜3倍）

内訳:
  record:  serialize(連想配列) × レコード数
  ids:     serialize 後 約 8〜12 bytes/entry（[id, ...] リスト形式）
  index:   [[value, [ids]], ...] × カラム数

× 2〜3倍: APCu 内部のオーバーヘッド（ハッシュテーブル、メモリフラグメンテーション）
```

目安:
- 1レコード 200 bytes × 50,000件 → record だけで約 10MB
- index・ids・オーバーヘッド込みで **30〜50MB** 程度
- 複数テーブルをキャッシュする場合は合算
- `apcu_cache_info('user')` で実際の使用量を確認し、使用率 80% 以下を維持

### APCu の制約と本番運用時の注意事項

#### APCu はプロセスローカル

APCu は **PHP-FPM のプロセスプール内（または CLI プロセス内）の共有メモリ** にデータを格納する。
**サーバー間では共有されない**。

```
サーバー A  [PHP-FPM]  ←→  APCu（サーバー A のみ）
サーバー B  [PHP-FPM]  ←→  APCu（サーバー B のみ）   ← 独立したキャッシュ
サーバー C  [PHP-FPM]  ←→  APCu（サーバー C のみ）   ← 独立したキャッシュ
```

**マルチサーバー構成での影響:**

- 各サーバーが独立したキャッシュを持つ
- デプロイ後、各サーバーが独立してキャッシュを再構築する（ids が消えた最初のリクエストで自動
  トリガー、または各サーバーに `POST /kura/warm` を呼ぶ）
- 1台のバージョン変更は他のサーバーに伝播しない — バージョン解決はサーバーごと・リクエストごと
- **推奨**: デプロイ後に各サーバーで warm エンドポイント（または `artisan kura:rebuild`）を呼ぶ

#### APCu は PHP CLI ではデフォルト無効

APCu の CLI での利用はデフォルトで無効（`apc.enable_cli=0`）。
`kura:rebuild` などの artisan コマンドで使う場合は有効化が必要:

```ini
; .docker/kura.ini または php.ini
apc.enable_cli = 1
```

#### apc.shm_size のチューニング

デフォルトの `apc.shm_size` は 32MB 程度が多く、本番のリファレンスデータには小さすぎる場合がある。
上記「apc.shm_size の見積もり」を参照して設定する:

```ini
apc.shm_size = 256M   ; データセットのサイズに合わせて調整
```

本番環境での監視:

```php
$info = apcu_cache_info('user');
// $info['mem_size'] = 確保済みメモリ総量
// $info['cache_list'] = キーごとの詳細
```

使用率は **80% 以下** を維持すること。超えると eviction が頻発し self-healing が過剰に走る。

---

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
public function cursor(
    array $wheres,
    array $orders,
    ?int $limit,
    ?int $offset,
    bool $randomOrder,
): Generator {
    // ロック中 → キャッシュの整合性が保証できない
    if ($repository->isLocked()) {
        yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);
        return;
    }

    $ids = $repository->ids();

    if ($ids === false) {
        // ids なし → Loader 直撃 + 全再構築 dispatch
        $this->dispatchRebuild();
        yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);
        return;
    }

    // Loader::indexes() からインデックス構造を取得（CacheProcessor インスタンスごとにキャッシュ）
    [$indexedColumns, $compositeNames] = $this->resolveIndexDefs();
    $resolver = new IndexResolver($store, $table, $version, $indexedColumns, $compositeNames);

    $candidateIds = $resolver->resolveIds($wheres) ?? $ids;

    $idsMap = array_fill_keys($ids, true);

    foreach ($candidateIds as $id) {
        $record = $repository->find($id);

        if ($record === null && isset($idsMap[$id])) {
            throw new CacheInconsistencyException("Record {$id} missing");
        }

        if ($record !== null && WhereEvaluator::evaluate($record, $wheres)) {
            yield $record;
        }
    }
}

// CacheProcessor::select() — get() から呼ばれる
public function select(
    array $wheres,
    array $orders,
    ?int $limit,
    ?int $offset,
    bool $randomOrder,
): array {
    try {
        return iterator_to_array($this->cursor($wheres, $orders, $limit, $offset, $randomOrder));
    } catch (CacheInconsistencyException) {
        $this->dispatchRebuild();
        return iterator_to_array($this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder));
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
- rebuild 完了 → `finally` ブロックで `apcu_delete()` を呼び出しロックを即時削除
- ロックキーの TTL はクラッシュ安全策のみ（プロセスが死んだ場合に TTL で自動消滅）

※ `apcu_add` はロック用途にのみ使用。データの書き込みは `apcu_store` 統一。

### Queue Job の原則

**「あれば TTL 延長、なければ作る」**

- APCu にある → 読み込んで再保存（`apcu_store` で TTL リセット）
- APCu にない → Loader からデータ取得して構築・保存
- 全部消えていても、一部だけ消えていても、同じロジックで動く
- Loader を呼んだら全キャッシュ（ids, record, index, cidx）を再構築する

**rebuild() は常に全件フラッシュ + 再構築**: まず `flush()` で対象テーブルの全キャッシュキーを削除し、
その後 Loader から全件再ロードする。部分的な再構築パスは存在しない —
ids、record、index、cidx はすべてロック内で一緒に再書き込みされる。

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
       └─ フラッシュ + 全件ロード → record + ids + indexes を APCu に書き込み（ロック中）
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
           └─ フラッシュ + ロード → APCu（ロック中、全体を通じて）

  次のリクエスト → APCu ヒット（通常の高速パス）

レイテンシ: 初回ミス = Loader 読み込み時間のみ（キャッシュ書き込みのオーバーヘッドなし）
Queue:      Laravel Queue 必須（Redis / SQS / database 等）
利用シーン: 本番環境 — キャッシュミスが呼び出し元に透過的
```

---

#### strategy: callback

Horizon 優先キュー / Octane タスク / カスタムテレメトリなど、任意のディスパッチロジックを使える。
`strategy` を `'callback'` に設定し、`config/kura.php` で callable を指定する。

```php
'rebuild' => [
    'strategy' => 'callback',
    'callback' => static function (\Kura\CacheRepository $repository): void {
        // 例: Horizon の特定キューに dispatch
        MyCustomRebuildJob::dispatch($repository->table())->onQueue('kura-rebuild');
    },
],
```

callable はリビルドが必要なテーブルの `CacheRepository` を受け取る。
`strategy` が `'callback'` の場合、`callback` の設定は必須。省略するとコンテナ解決時に `InvalidArgumentException` が投げられる。

```
get() / first() — キャッシュミス検知
  │
  ├─ ($yourCallable)($repository)  ← 独自ロジックをここで実行
  └─ Loader から返却

レイテンシ: callable の実装次第
Queue:      任意
利用シーン: Horizon 優先キュー / Octane / カスタムテレメトリ等
```

---

#### 比較

| strategy | Queue 必要 | ミス時レイテンシ | 利用シーン |
|---|---|---|---|
| **sync** | 不要 | Loader + 再構築時間 | 開発 / 小規模 / Queue なし |
| **queue** | 必要（Laravel Queue） | Loader のみ | 本番（推奨） |
| **カスタム**（`app->extend`） | 任意 | 実装次第 | カスタムインフラ / Horizon |

---

## Warm エンドポイント

`POST /kura/warm` は登録済みテーブル（または指定したテーブル）の APCu キャッシュを再構築する。
デプロイ後にトラフィックが来る前に事前ウォームアップするのに使う。

`config/kura.php` で有効化:

```php
'warm' => [
    'enabled'           => true,
    'token'             => env('KURA_WARM_TOKEN', ''),  // Bearer トークン（必須）
    'path'              => 'kura/warm',                  // URL パス
    'controller'        => \Kura\Http\Controllers\WarmController::class,
    'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
],
```

トークンの生成:

```bash
php artisan kura:token          # 生成して .env に書き込む
php artisan kura:token --show   # 現在のトークンを表示
php artisan kura:token --force  # 確認なしで上書き
```

**コントローラーのカスタマイズ** — スタブを `app/Http/Controllers/Kura/` にコピー:

```bash
php artisan vendor:publish --tag=kura-controllers
```

カスタムクラスを config に指定:

```php
'warm' => [
    'controller'        => \App\Http\Controllers\Kura\WarmController::class,
    'status_controller' => \App\Http\Controllers\Kura\WarmStatusController::class,
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
`job_batches` テーブルに保存されており、ステータスエンドポイントで進捗を確認できる:

```
GET /kura/warm/status/{batchId}
Authorization: Bearer {KURA_WARM_TOKEN}

レスポンス 200:
{
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "total":     3,
  "pending":   1,
  "failed":    0,
  "finished":  false,
  "cancelled": false
}

レスポンス 404: {"message": "Batch not found."}
```

`WarmStatusController` は `Bus::findBatch()` を直接使わず `BatchFinderInterface` に依存しているため、
テスト時に Mockery なしで自作 fake に差し替えられる。

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
    'record'     => 4800,    // 1時間20分
    'index'      => 4800,    // 1時間20分
    'ids_jitter' => 600,     // ids TTL に加算するランダム値（0〜600秒）スパイク防止
],
```

### 関係

```
ids (3600) < record / index / cidx (4800)
```

- **ids が最初に消える** → 再構築トリガー
- **record/index はまだ生きている** → 再構築中もクエリに応答可能
- インデックス構造は `Loader::indexes()` から常に取得可能（APCu への依存なし）

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
        'ids'        => 3600,   // 最短 — 失効が再構築トリガー
        'record'     => 4800,
        'index'      => 4800,
        'ids_jitter' => 600,    // ids TTL に加算するランダム値（0〜600秒）スパイク防止
    ],

    'lock_ttl' => 60,  // rebuild ロックの TTL（秒）。Loader 実行時間の 1.5〜2倍を目安に設定

    'rebuild' => [
        'strategy' => 'sync',   // 'sync' | 'queue' | 'callback'
        'queue' => [
            'connection' => null,  // null = デフォルト接続
            'queue'      => null,  // null = デフォルトキュー
            'retry'      => 3,
        ],
    ],

    'warm' => [
        'enabled'           => false,
        'token'             => env('KURA_WARM_TOKEN', ''),
        'path'              => 'kura/warm',
        'controller'        => \Kura\Http\Controllers\WarmController::class,
        'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
    ],

    'tables' => [
        // テーブル単位でオーバーライドしたい場合のみ
        // 'products' => [
        //     'ttl' => ['record' => 7200],
        // ],
    ],
];
```

---

## キー構造

```
{prefix}:{table}:{version}:ids                     — 全 ID リスト [id, ...]
{prefix}:{table}:{version}:record:{id}             — 1レコード（連想配列）
{prefix}:{table}:{version}:idx:{col}               — index（カラムごとに単一キー）
{prefix}:{table}:{version}:cidx:{col1|col2}        — composite index（hashmap）
{prefix}:{table}:lock                               — rebuild ロック（version 非依存）
```

デフォルト（prefix=`kura`）:
```
kura:products:v1.0.0:ids
kura:products:v1.0.0:record:1
kura:products:v1.0.0:idx:country
kura:products:v1.0.0:idx:price
kura:products:v1.0.0:cidx:country|category    — composite index
```
