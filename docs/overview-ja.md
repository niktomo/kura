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
│   ├── ReferenceQueryBuilderInterface.php
│   ├── VersionResolverInterface.php   バージョン解決の共通インターフェース
│   └── VersionsLoaderInterface.php    全バージョン行を読み込むインターフェース
├── Console/
│   ├── RebuildCommand.php             artisan kura:rebuild
│   └── TokenCommand.php               artisan kura:token（Bearer トークン生成）
├── Exceptions/
│   ├── CacheInconsistencyException.php
│   ├── RecordsNotFoundException.php
│   └── MultipleRecordsFoundException.php
├── Http/
│   ├── Controllers/
│   │   ├── WarmController.php         POST /kura/warm（invokable）
│   │   └── WarmStatusController.php   GET /kura/warm/status/{batchId}（invokable）
│   ├── Batch/
│   │   ├── BatchFinderInterface.php   バッチ検索の抽象（テスタブル）
│   │   ├── BatchSummary.php           バッチ進捗の読み取り専用 DTO
│   │   └── LaravelBatchFinder.php     Bus::findBatch() をラップした本番実装
│   └── Middleware/
│       └── KuraAuthMiddleware.php     warm ルートの Bearer トークン認証
├── Index/
│   ├── IndexDefinition.php            インデックス定義 DTO（unique / non-unique）
│   ├── IndexBuilder.php               インデックス構築（ソート・composite）
│   ├── IndexResolver.php              index からの候補 ID 解決
│   └── BinarySearch.php               ソート済み index の binary search
├── Jobs/
│   └── RebuildCacheJob.php            非同期キャッシュ再構築ジョブ
├── Loader/
│   ├── LoaderInterface.php            データ取得の抽象インターフェース
│   ├── TableDefinitionReader.php      defines.csv / indexes.csv を読み込む
│   ├── CsvLoader.php                  CSV ベースの Loader（data.csv + defines.csv + indexes.csv）
│   ├── CsvVersionResolver.php         versions.csv から全バージョン行を読み込む（VersionsLoaderInterface）
│   ├── EloquentLoader.php             Eloquent モデルベースの Loader（defines/indexes は tableDirectory から読込）
│   ├── QueryBuilderLoader.php         QueryBuilder ベースの Loader（defines/indexes は tableDirectory から読込）
│   └── StaticVersionResolver.php      固定バージョン文字列のリゾルバー（シンプルな構成・テスト用）
├── Store/
│   ├── StoreInterface.php             APCu 操作の抽象インターフェース
│   ├── ApcuStore.php                  本番用 APCu 実装
│   └── ArrayStore.php                 テスト用インメモリ実装
├── Version/
│   ├── DatabaseVersionResolver.php    DB テーブルから全バージョン行を読み込む（VersionsLoaderInterface）
│   ├── CachedVersionResolver.php      全行を APCu にキャッシュし、resolve() のたびに now() でフィルタ
│   └── SystemClock.php                PSR-20 ClockInterface 実装（現在時刻を返す）
└── Support/
    ├── RecordCursor.php               Generator ベースのカーソル（streaming / sorted / random）
    └── WhereEvaluator.php             ステートレスな where 条件評価器（static メソッド）
```

---

## APCu キー構造

```
{prefix}:{table}:{version}:ids                     全 PK リスト [id, ...]
{prefix}:{table}:{version}:record:{id}             1 レコード（連想配列）
{prefix}:{table}:{version}:idx:{col}               index（カラムごとに単一キー）
{prefix}:{table}:{version}:cidx:{col1|col2}        composite index（hashmap）
{prefix}:{table}:lock                               rebuild ロック（version 非依存）
```

インデックス構造（どのカラムがインデックスされているか、composite の一覧）は **APCu には保存しない**。
クエリ時に `LoaderInterface::indexes()` から取得する（Loader がインスタンスキャッシュ）。

### TTL の考え方

| キー | TTL | 役割 |
|------|-----|------|
| `pks` | 短い（例: 3600秒） | 失効 → 全再構築トリガー |
| `record:*` | 長い（例: 4800秒） | 失効 → pks にある → 全再構築 |
| `index` | ids と同じ（デフォルト） | 失効 → `IndexInconsistencyException` → 再構築 |
| `cidx` | ids と同じ（デフォルト） | 失効 → `IndexInconsistencyException` → 再構築 |

TTL は `config/kura.php` で設定。`pks` が最短（再構築トリガーの役割）。`index` はデフォルトで `pks` と同じ TTL（jitter 込み）になるよう設計されており、同タイミングで失効する。

`LoaderInterface::indexes()` で宣言済みのインデックスに対して APCu キーが欠損している場合（`IndexInconsistencyException`）は、`CacheInconsistencyException` と同じ回復パス（rebuild + Loader フォールバック）を辿る。

### バージョン管理の仕組み

バージョンは `VersionResolverInterface` で解決する。

- `DatabaseVersionResolver`（`src/Version/`）— `VersionsLoaderInterface` 実装。DB `reference_versions` テーブルから全行を読み込む
- `CsvVersionResolver`（`src/Loader/`）— `VersionsLoaderInterface` 実装。versions.csv から全行を読み込む
- `CachedVersionResolver`（`src/Version/`）— `VersionsLoaderInterface` をラップ。全行を APCu にキャッシュし、`resolve()` のたびに `clock->now()` でフィルタ（デフォルト TTL: 5分）

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
       └─ apcu_store({version}:idx:*)        ← ロック内で書き込み（ループ後）
       └─ apcu_store({version}:cidx:*)       ← ロック内で書き込み（ループ後）
```

### クエリ実行時の self-healing

```
ReferenceQueryBuilder::get()
  ├─ pks あり → 通常クエリ（Loader::indexes() からインデックス構造を取得）
  ├─ pks なし → Loader 直撃 + rebuild dispatch
  └─ record 欠損 + pks にある → CacheInconsistencyException → rebuild
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
  ├─ 役割: テーブル単位のキャッシュ管理。ids / record の取得・rebuild
  ├─ 依存: StoreInterface, LoaderInterface
  └─ 責務:
       ├─ pks() — pks キーがなければ false
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
  └─ getIds / putIds
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

CsvLoader / EloquentLoader / QueryBuilderLoader は src/Loader/ に含まれる

CsvLoader のファイル構成:
  {tableDirectory}/
    data.csv      — version カラム付きのデータ（必須）
    defines.csv   — カラム名 → 型のマッピング
    indexes.csv   — インデックス定義（省略可。省略時はコンストラクタ引数を使用）

indexes.csv のフォーマット:
  columns,unique
  prefecture,false       — 単一カラムインデックス
  email,true             — ユニークインデックス
  country|type,false     — composite インデックス（| 区切り）

自動発見（config: kura.csv.auto_discover = true）:
  KuraServiceProvider が kura.csv.base_path 配下をスキャン。
  data.csv を含むサブディレクトリが CsvLoader テーブルとして自動登録される。
  versions.csv は base_path/versions.csv を共通で使用。
  primary_key の上書き: config('kura.tables.{table}.primary_key')
  注意: 新しいテーブルディレクトリを追加した場合は PHP プロセスの再起動が必要
        （ServiceProvider の boot は起動時に1回だけ実行されるため）
```

### Index 層

```
IndexDefinition
  └─ unique() / nonUnique() static factory
     columns: list<string>, unique: bool

IndexBuilder
  └─ rebuild 時に index を構築
     buildSorted(): [[value, [ids]], ...] ソート済みリスト
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
     オプションの $version パラメーターでバージョン指定可能
```

### HTTP 層

```
WarmController（POST /kura/warm）
  └─ 全登録テーブル（または指定テーブル）のキャッシュを再構築
     strategy=sync  → 直列 rebuild、200 を返す
     strategy=queue → Bus::batch() dispatch、batch_id つき 202 を返す
     カスタマイズ可: vendor:publish --tag=kura-controllers でコピー

WarmStatusController（GET /kura/warm/status/{batchId}）
  └─ キューイングされた warm バッチの進捗を返す
     Bus facade を直接使わず BatchFinderInterface に依存（テスタブル）

BatchFinderInterface / BatchSummary / LaravelBatchFinder
  └─ Bus::findBatch() の抽象化
     BatchSummary: id, totalJobs, pendingJobs, failedJobs, finished, cancelled
     テスト時は LaravelBatchFinder を自作 fake に差し替え可（Mockery 不要）

KuraAuthMiddleware
  └─ Authorization: Bearer {KURA_WARM_TOKEN} を検証
     両 warm ルートに自動適用
```

### クラス依存関係図

```
ReferenceQueryBuilder
  └── CacheProcessor
        ├── CacheRepository
        │     ├── StoreInterface ←── ApcuStore / ArrayStore
        │     └── LoaderInterface ←── CsvLoader / EloquentLoader（src/Loader/）
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

`data.csv` には `version` カラムが必須です。CsvLoader は `version <= 現在のバージョン` または `version が NULL` の行を読み込みます。`version` が NULL の行は全バージョン共通データとして常にロードされ、未来バージョンの行はスキップされます。

```csv
id,name,price,version
1,商品A,9.99,
2,商品B,19.99,v1.0.0
3,商品C,29.99,v1.1.0
```

**空セルは `null` として扱われます。** `data.csv` の空セル（上記の行1の `version` カラムなど）はレコード内で `null` として保存されます。これは標準的な CSV の慣例に従っており、Kura 全体で使用している MySQL の NULL セマンティクスと一致しています。

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
