> English version: [overview.md](overview.md)

# Kura — 構成と使用方法

## 概要

Kura は、参照データを APCu にキャッシュし、Laravel の `QueryBuilder` と同じ API で検索できる Laravel パッケージです。

- DB にクエリを投げず、メモリ上のキャッシュから高速に取得
- データ取得は Generator ベースで省メモリ
- キャッシュ構築は Laravel Queue に委譲（ノンブロッキング）
- キャッシュが消えても自動で自己修復（self-healing）

---

## ディレクトリ構成

```
src/
├── ReferenceQueryBuilder.php          メインの fluent クエリビルダー
├── CacheProcessor.php                 Processor パターン — 実行を担当
├── CacheRepository.php                テーブル単位のキャッシュ管理・self-healing
├── KuraManager.php                  テーブル登録・クエリ・rebuild の中央レジストリ
├── KuraServiceProvider.php          Laravel サービスプロバイダ
├── Concerns/
│   ├── BuildsWhereConditions.php      where 系メソッド群
│   ├── BuildsOrderAndPagination.php   orderBy / paginate 系メソッド群
│   └── ExecutesQueries.php            get / first / find など実行系メソッド群
├── Contracts/
│   └── ReferenceQueryBuilderInterface.php
├── Console/
│   └── RebuildCommand.php             artisan kura:rebuild
├── Index/
│   ├── IndexDefinition.php            インデックス定義 DTO（unique / non-unique）
│   ├── IndexBuilder.php               インデックス構築（ソート・chunk 分割・composite）
│   ├── IndexResolver.php              index からの候補 ID 解決
│   └── BinarySearch.php               ソート済み index の binary search
├── Jobs/
│   └── RebuildCacheJob.php            非同期キャッシュ再構築ジョブ
├── Loader/
│   └── LoaderInterface.php            データ取得の抽象インターフェース（実装は別パッケージ）
├── Store/
│   ├── StoreInterface.php             APCu 操作の抽象インターフェース
│   ├── ApcuStore.php                  本番用 APCu 実装
│   └── ArrayStore.php                 テスト用インメモリ実装
├── Version/
│   ├── VersionResolverInterface.php   バージョン解決の共通インターフェース
│   ├── DatabaseVersionResolver.php    DB reference_versions テーブルから解決
│   ├── CsvVersionResolver.php         CSV versions.csv から解決
│   └── CachedVersionResolver.php      デコレータ（APCu + PHP var でキャッシュ）
└── Support/
    ├── RecordCursor.php               Generator ベースのカーソル（streaming / sorted / random）
    └── WhereEvaluator.php             ステートレスな where 条件評価器（static メソッド）
```

---

## APCu キー構造

```
{prefix}:{table}:{version}:meta                    メタ情報（columns + indexes + composites）
{prefix}:{table}:{version}:ids                     全 ID リスト [id, ...]
{prefix}:{table}:{version}:record:{id}             1 レコード（連想配列）
{prefix}:{table}:{version}:idx:{col}               index（chunk なし）
{prefix}:{table}:{version}:idx:{col}:{chunk}       index（chunk あり）
{prefix}:{table}:{version}:cidx:{col1|col2}        composite index（hashmap）
{prefix}:{table}:lock                               rebuild ロック（version 非依存）
```

### TTL の考え方

| キー | TTL | 役割 |
|------|-----|------|
| `ids` | 短い（例: 3600秒） | 失効 → 全再構築トリガー |
| `meta` | 長い（例: 4800秒） | 失効 → full scan + 再構築 |
| `record:*` | 長い（例: 4800秒） | 失効 → ids にある → 全再構築 |
| `index` | 長い（例: 4800秒） | 失効 → full scan + 再構築 |
| `cidx` | 長い（例: 4800秒） | 失効 → full scan + 再構築 |

TTL は `config/kura.php` で設定。ids が最短（再構築トリガーの役割）。

### バージョン管理の仕組み

バージョンは `VersionResolverInterface` で解決する。

- `DatabaseVersionResolver` — DB `reference_versions` テーブル（id, version, activated_at）
- `CsvVersionResolver` — CSV versions.csv（id, version, activated_at）
- `CachedVersionResolver` — デコレータ。APCu + PHP var でキャッシュ（default 5分）

version が変わるとキャッシュキーが変わり、旧キャッシュは自然に TTL 消滅する。

### Middleware

**`KuraVersionMiddleware`**（`examples/` にサンプル）をリクエストの先頭で実行し、1リクエスト中のバージョンを固定する。
`X-Reference-Version` ヘッダーでバージョンを明示指定可能。

```
HTTP Request
  └─ KuraVersionMiddleware
       └─ 各テーブルのアクティブバージョンを解決・コンテナにバインド
  └─ Controller
       └─ 以降のクエリはすべてバインド済みバージョンを使用
```

### キャッシュ書き込みルール

**全キー `apcu_store` で統一。**

- `apcu_store` は上書き。再 store するたびに TTL がリセット（延長）される
- `apcu_add` はロック用途にのみ使用

---

## データフロー

### 初回 / キャッシュ再構築

```
artisan kura:rebuild
  └─ KuraManager::rebuild($table)

RebuildCacheJob（非同期）
  └─ Loader::load()                     ← Generator でレコードをストリーミング
       └─ apcu_store({version}:record:{id})  ← 1件ずつ書き込み
       └─ apcu_store({version}:ids)          ← ループ後に一括
       └─ apcu_store({version}:idx:*)        ← Phase 2 で構築
       └─ apcu_store({version}:cidx:*)       ← Phase 2 で構築
       └─ apcu_store({version}:meta)         ← Phase 2 で構築
```

### クエリ実行時の self-healing

```
ReferenceQueryBuilder::get()
  ├─ ids あり + meta あり → 通常クエリ（index 活用）
  ├─ ids あり + meta なし → full scan で応答 + rebuild dispatch
  ├─ ids なし → Loader 直撃 + rebuild dispatch
  └─ record 欠損 + ids にある → CacheInconsistencyException → rebuild
```

---

## クラス構成と責務

### コアクラス

```
ReferenceQueryBuilder
  ├─ 役割: fluent API のエントリポイント。where / orderBy / get / paginate などを提供
  ├─ 依存: CacheRepository, CacheProcessor
  └─ trait:
       ├─ BuildsWhereConditions      — where / orWhere / whereBetween / whereIn / whereRowValuesIn など
       ├─ BuildsOrderAndPagination   — orderBy / limit / offset / paginate / simplePaginate
       └─ ExecutesQueries            — get / first / find / sole / count / min / max / sum / avg など

CacheProcessor
  ├─ 役割: キャッシュからの実行（select, cursor）
  ├─ 依存: CacheRepository, StoreInterface
  └─ 責務:
       ├─ resolveIds() — IndexResolver を使い index で候補 ID を絞り込む
       ├─ compilePredicate() — where 条件をクロージャに変換
       └─ cursor() / select() — レコード取得・Self-Healing

CacheRepository
  ├─ 役割: テーブル単位のキャッシュ管理。ids / record / meta の取得・rebuild
  ├─ 依存: StoreInterface, LoaderInterface
  └─ 責務:
       ├─ ids() — ids キーがなければ false
       ├─ find(id) — record 取得
       └─ rebuild() — Loader を回して全キャッシュ構築

KuraManager
  ├─ 役割: テーブル登録・クエリ・rebuild の中央レジストリ
  └─ 責務:
       ├─ query($table) — ReferenceQueryBuilder を返す
       ├─ rebuild($table) — 指定テーブルのキャッシュ再構築
       └─ setVersionOverride() — artisan 等で外部からバージョン指定

RecordCursor（Support）
  ├─ 役割: Generator ベースのカーソル。streaming / sorted / random 走査
  └─ 責務: ID を順に取得し、述語評価を WhereEvaluator に委譲して yield する

WhereEvaluator（Support）
  ├─ 役割: ステートレスな where 条件評価器
  └─ 責務: evaluate(record, wheres) — where ツリーを純粋評価（static のみ）
```

### Store 層（APCu 抽象化）

```
StoreInterface
  └─ getMeta / putMeta
     getIds / putIds
     getRecord / putRecord
     getIndex / putIndex
     getCompositeIndex / putCompositeIndex

ApcuStore  — 本番用。apcu_store（上書き + TTL 延長）で書き込む
ArrayStore — テスト用。PHPメモリ上の連想配列で動作
```

### Loader 層（データソース抽象化）

```
LoaderInterface
  └─ load(): Generator<int, array<string, mixed>>   全レコードを yield
     columns(): array<string, string>                カラム名 → 型
     indexes(): list<array{columns, unique}>         インデックス定義
     version(): string|int|Stringable                バージョン識別子

実装は別パッケージ（CsvLoader, EloquentLoader 等）
```

### Index 層

```
IndexDefinition
  └─ unique() / nonUnique() static factory
     columns: list<string>, unique: bool

IndexBuilder
  └─ rebuild 時に index を構築
     buildSorted(): [[value, [ids]], ...] ソート済みリスト
     buildChunked(): chunk 分割
     buildCompositeIndexes(): composite index hashmap
     composite 宣言時に各カラムの単カラム index も自動作成

IndexResolver
  └─ クエリ時に index から候補 ID を解決
     resolveIds(): 複数条件の AND/OR
     tryCompositeIndex(): composite index で AND equality を O(1) 解決
     resolveRowValuesIn(): ROW constructor IN を composite index で加速

BinarySearch
  └─ ソート済み [[value, [ids]], ...] に対する検索
     equal / greaterThan / lessThan / between
```

### Queue Jobs

```
RebuildCacheJob
  └─ KuraManager::rebuild() に委譲
     tries: 3（config でオーバーライド可能）
     テーブル単位で実行
```

### クラス依存関係図

```
ReferenceQueryBuilder
  └── CacheProcessor
        ├── CacheRepository
        │     ├── StoreInterface ←── ApcuStore / ArrayStore
        │     └── LoaderInterface ←── 別パッケージで実装
        └── IndexResolver
              └── StoreInterface

KuraManager
  └── CacheRepository (per table)

RecordCursor
  └── WhereEvaluator (standalone — ステートレス、依存なし)
```

---

## CSV ファイル構成

1テーブル = 1ディレクトリ。`versions.csv` は全テーブルで共通（親ディレクトリに置く）。

```
data/
├── versions.csv                 id, version, activated_at
└── products/
    ├── defines.csv              column, type, description
    ├── indexes.csv              columns, unique
    └── data.csv                 version カラムありのデータ行
```

### versions.csv

```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v1.1.0,2024-06-01 00:00:00
```

`activated_at <= 現在時刻` の中で最も新しいバージョンが使われます。

### data.csv

`data.csv` には `version` カラムが必須です。CsvLoader は `version = 現在のバージョン` または `version が NULL` の行を読み込みます。`version` が NULL の行は全バージョン共通データとして常にロードされます。

```csv
id,name,price,version
1,商品A,9.99,
2,商品B,19.99,v1.0.0
3,商品C,29.99,v1.1.0
```

### defines.csv

```csv
column,type,description
id,int,商品ID
name,string,商品名
price,float,価格
active,bool,販売中フラグ
```

サポートする型: `int` / `float` / `bool` / `string`

---

## 使用方法

### 1. インデックス定義

```php
use Kura\Index\IndexDefinition;

$indexes = [
    IndexDefinition::nonUnique('country'),          // 通常インデックス
    IndexDefinition::unique('code'),                // ユニークインデックス
    IndexDefinition::nonUnique('country', 'type'),  // composite インデックス
];
```

### 2. リポジトリとクエリビルダーの構築

```php
use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ApcuStore;

$store = new ApcuStore;

$repository = new CacheRepository(
    table: 'products',
    primaryKey: 'id',
    store: $store,
    loader: $loader,   // LoaderInterface 実装
);

$processor = new CacheProcessor($repository, $store);

$builder = new ReferenceQueryBuilder(
    table: 'products',
    repository: $repository,
    processor: $processor,
);
```

### 3. クエリ

```php
// 全件取得
$products = $builder->get();

// 条件絞り込み
$jpProducts = $builder->where('country', 'JP')->get();

// ソート・ページネーション
$page = $builder->orderBy('name')->paginate(20, page: 2);

// 単件取得
$product = $builder->find(42);
$product = $builder->where('code', 'ABC-001')->sole();

// 集計
$max = $builder->where('active', true)->max('price');
$avg = $builder->avg('price');

// ROW constructor IN（Kura 拡張）
$result = $builder
    ->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [2, 20]])
    ->get();
```

---

## 独自 Loader の実装

`LoaderInterface` を実装するだけで任意のデータソースに対応できます。

```php
use Kura\Loader\LoaderInterface;

class EloquentLoader implements LoaderInterface
{
    public function __construct(private string $modelClass) {}

    public function load(): \Generator
    {
        foreach ($this->modelClass::cursor() as $model) {
            yield $model->toArray();
        }
    }

    public function columns(): array
    {
        return ['id' => 'int', 'name' => 'string', 'price' => 'float'];
    }

    public function indexes(): array
    {
        return [
            ['columns' => ['name'], 'unique' => false],
        ];
    }

    public function version(): string
    {
        return 'v1.0.0';
    }
}
```

---

## 関連ドキュメント

- [`docs/cache-architecture.md`](./cache-architecture.md) — キャッシュ設計の詳細（TTL・Queue・self-healing）
- [`docs/laravel-builder-coverage.md`](./laravel-builder-coverage.md) — Laravel QueryBuilder との API 対応表
