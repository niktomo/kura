> English version: [README.md](README.md)

> [!WARNING]
> このパッケージは現在開発中です。v1.0.0 リリースまで API が予告なく変更される可能性があります。

# Kura

[![Tests](https://github.com/niktomo/kura/actions/workflows/tests.yml/badge.svg)](https://github.com/niktomo/kura/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/niktomo/kura.svg)](https://packagist.org/packages/niktomo/kura)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/niktomo/kura)](LICENSE)

**Kura**（蔵）は、リファレンスデータを APCu にキャッシュし、**Laravel QueryBuilder 互換の API** で検索できる Laravel パッケージです。

CSV や DB からデータを一度読み込み、APCu に保存し、いつもの fluent API でクエリ — **実行時の DB クエリは不要**です。インデックスによる高速化でサブミリ秒のレスポンスを実現します。

## なぜ Kura？

- **馴染みのある API** — `where`, `orderBy`, `paginate`, `find`, `count`, `sum` — Laravel の QueryBuilder と同じ
- **サブミリ秒の読み取り** — APCu 共有メモリ、ネットワーク往復なし（[ベンチマーク参照](#ベンチマーク)）
- **低メモリ使用量** — Generator ベースの走査、全件をメモリに載せない
- **スマートインデックス** — 二分探索インデックスで範囲クエリ対応、composite index hashmap で O(1) 複合カラム検索、大規模データセット向けの自動チャンク分割
- **Self-Healing** — キャッシュが消えても自動再構築 — アプリケーションからは常に完全なデータが見える
- **バージョン管理** — DB や CSV でリファレンスデータのバージョンをシームレスに切り替え
- **データソースの差し替え自由** — `LoaderInterface` を実装するだけで CSV・Eloquent・QueryBuilder・REST API・S3 など任意のバックエンドに対応。組み込み Loader を使うか、4メソッドで自作して差し替えできる

## 要件

- PHP 8.2 / 8.3 / 8.4（8.5 以降も動作する見込み）
- Laravel ^10.0 / ^11.0 / ^12.0 / ^13.0
- APCu 拡張 (`pecl install apcu`)

## インストール

```bash
composer require niktomo/kura
php artisan vendor:publish --tag=kura-config
```

---

## クイックスタート

### 1. 設定

`config/kura.php` を編集 — 最初に設定すべき主なセクション:

```php
// config/kura.php
return [
    'prefix' => 'kura',

    // バージョン解決 — どのデータバージョンを使うか
    'version' => [
        'driver'    => 'csv',                           // 'csv' or 'database'
        'csv_path'  => base_path('data/versions.csv'),  // versions.csv のパス
        'cache_ttl' => 300,                             // 全バージョン行を APCu に5分キャッシュ
    ],

    // Rebuild 戦略 — キャッシュが消えたときの動作
    'rebuild' => [
        'strategy' => 'sync',  // 'sync' | 'queue'（本番推奨）| 'callback'
    ],
];
```

### 2. データを用意する

Kura は **CSV ファイル** と **Database（Eloquent）** の2つのデータソースに対応しています。

#### パターン A: CSV ファイル

テーブルごとにディレクトリを作成し、共通の `versions.csv` を用意:

```
data/
├── versions.csv           # 共通のバージョン管理
└── stations/
    ├── defines.csv        # カラム定義
    ├── indexes.csv        # インデックス定義（省略可）
    └── data.csv           # データ本体（version カラム必須）
```

**versions.csv** — アクティブなバージョンを制御:
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
```

`activated_at <= 現在時刻` で最も新しいバージョンが使われます。

**stations/defines.csv** — カラム名と型:
```csv
column,type,description
id,int,駅ID
name,string,駅名
prefecture,string,都道府県
city,string,市区町村
lat,float,緯度
lng,float,経度
line_id,int,路線ID
version,string,データバージョン（必須）
```

サポートする型: `int`, `float`, `bool`, `string`

**stations/indexes.csv** — 省略可。高速検索するカラムのインデックスを定義:
```csv
columns,unique
prefecture,false
line_id,false
prefecture|city,false
```

- `columns`: カラム名。composite インデックスは `|` 区切り
- `unique`: `true` / `false`
- composite インデックス（`col1|col2`）は複数カラムの等値検索を O(1) で解決

> **補足:** インデックスが不要な場合は `indexes.csv` を省略するだけでよいです。すべての Loader は空のインデックスリストを返します。

**stations/data.csv** — データ本体（`version` カラムあり）:
```csv
id,name,prefecture,city,lat,lng,line_id,version
1,東京,Tokyo,千代田区,35.6812,139.7671,1,
2,渋谷,Tokyo,渋谷区,35.6580,139.7016,1,
3,新宿,Tokyo,新宿区,35.6896,139.7006,1,v1.0.0
4,大阪,Osaka,北区,34.7024,135.4959,2,v1.0.0
5,なんば,Osaka,中央区,34.6629,135.5013,3,v1.0.0
```

CsvLoader は `version が NULL`（全バージョン共通データとして常にロード）または `version <= 現在のバージョン`（過去・現在のバージョン行）を読み込みます。`version > 現在のバージョン` の行はスキップされます（未来のバージョン）。

#### パターン B: Database（Eloquent）

データ CSV 不要 — データベースから直接読み込めます。カラム定義とインデックス宣言は CSV ローダーと同じ `defines.csv` / `indexes.csv` から読み込みます:

```
data/stations/
├── defines.csv    # カラム型定義（必須）
└── indexes.csv    # インデックス宣言（省略可）
```

```php
use Kura\Loader\EloquentLoader;
use Kura\Loader\StaticVersionResolver;

$loader = new EloquentLoader(
    query: Station::query(),
    tableDirectory: base_path('data/stations'),
    resolver: new StaticVersionResolver('v1.0.0'),
);
```

バージョン管理 resolver を使う場合（本番推奨）:

```php
use Kura\Loader\EloquentLoader;
use Kura\Contracts\VersionResolverInterface;

$loader = new EloquentLoader(
    query: Station::query(),
    tableDirectory: base_path('data/stations'),
    resolver: app(VersionResolverInterface::class),
);
```

#### パターン C: 自作 Loader

どんなデータソースでも対応可能 — `LoaderInterface` の4メソッドを実装するだけ:

```php
use Kura\Loader\LoaderInterface;

class MyApiLoader implements LoaderInterface
{
    public function load(): \Generator { /* レコードを fetch して yield */ }
    public function columns(): array   { /* カラム名 → 型のマップ */ }
    public function indexes(): array   { /* インデックス定義 */ }
    public function version(): string  { /* キャッシュキーの識別子 */ }
}
```

詳細は [独自 Loader の実装](docs/overview-ja.md#独自-loader-の実装) を参照。

### 3. テーブルを登録する

#### パターン A: 自動発見（CSV のみ）

CSV ファイルを使う場合の最も簡単な方法です。Kura が指定ディレクトリを自動スキャンし、`data.csv` を含むサブディレクトリをテーブルとして自動登録します。`AppServiceProvider` へのコード追加不要。

```php
// config/kura.php
'csv' => [
    'base_path'     => storage_path('reference'),  // スキャン対象ディレクトリ
    'auto_discover' => true,
],
```

```
storage/reference/
├── versions.csv        # 共通のバージョン管理
├── stations/
│   ├── data.csv
│   ├── defines.csv
│   └── indexes.csv
└── lines/
    ├── data.csv
    ├── defines.csv
    └── indexes.csv
```

これだけで `stations` と `lines` が自動登録されます。primary key を変更したいテーブルだけ config で上書き:

```php
// config/kura.php
'tables' => [
    'products' => ['primary_key' => 'product_code'],
],
```

> **注意:** 新しいテーブルディレクトリを追加した場合は PHP プロセスの再起動が必要です（Octane なら `php artisan octane:restart`、PHP-FPM なら reload）。ディレクトリスキャンは起動時に1回だけ実行されるためです。既存テーブルのデータ更新（data.csv の差し替え）は PHP 再起動は不要ですが、`php artisan kura:rebuild` の実行が必要です。ファイル変更の自動検知機能はなく、Self-Healing は APCu の TTL 切れ時にのみ発動します。

#### パターン B: 手動登録

`AppServiceProvider`（または専用のサービスプロバイダ）で:

```php
use Kura\Facades\Kura;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;

public function boot(): void
{
    // CSV の例
    $resolver = new CsvVersionResolver(base_path('data/versions.csv'));

    Kura::register('stations', new CsvLoader(
        tableDirectory: base_path('data/stations'),
        resolver: $resolver,
    ), primaryKey: 'id');

    // 複数テーブルの登録も可能
    Kura::register('lines', new CsvLoader(
        tableDirectory: base_path('data/lines'),
        resolver: $resolver,
    ), primaryKey: 'id');
}
```

### 4. キャッシュを構築する

```bash
# 登録済みの全テーブルを rebuild
php artisan kura:rebuild

# 特定テーブルのみ
php artisan kura:rebuild stations

# バージョンを指定して rebuild
php artisan kura:rebuild --reference-version=v2.0.0
```

### 5. クエリ

```php
use Kura\Facades\Kura;

// 主キーで検索
$station = Kura::table('stations')->find(1);

// フィルタ
$tokyoStations = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->get();

// ソート & ページネーション
$page = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orderBy('name')
    ->paginate(20);

// 集計
$count = Kura::table('stations')->where('prefecture', 'Osaka')->count();
$maxLat = Kura::table('stations')->max('lat');
$avgLng = Kura::table('stations')->where('line_id', 1)->avg('lng');

// クロステーブルフィルタリング（遅延サブクエリ）
$kantoLineIds = fn() => Kura::table('lines')
    ->where('region', 'Kanto')
    ->pluck('id');
$stations = Kura::table('stations')
    ->whereIn('line_id', $kantoLineIds)
    ->get();
```

---

## 仕組み

`kura:rebuild` を実行すると、Kura はデータソース（CSV または DB）からデータを読み込み、各レコードを APCu に保存し、検索インデックスを構築します。以降のクエリは共有メモリから直接読み取り — データベースは不要です。

```
データソース（CSV / DB）
  └─ Generator ストリーミング（省メモリ）
       └─ APCu: レコード + インデックス + メタデータ
            └─ QueryBuilder API → サブミリ秒レスポンス
```

### APCu キー構造

```
kura:stations:v1.0.0:ids                    # 全 ID リスト
kura:stations:v1.0.0:record:1               # 1レコード
kura:stations:v1.0.0:idx:prefecture         # 検索インデックス（単カラム）
kura:stations:v1.0.0:cidx:prefecture|city   # composite index（O(1) 複合カラム検索）
```

### Self-Healing

APCu がキャッシュデータを evict しても、Kura はクエリ時に欠損を検知し、データソースから自動的に再構築します。アプリケーションは常に完全で正確な結果を受け取ります。

```
クエリ
  ├─ キャッシュヒット → APCu から応答（通常パス）
  ├─ キャッシュミス → Loader から応答 + rebuild ディスパッチ
  └─ クエリ途中のレコード欠損 → Loader にフォールバック
```

`rebuild.strategy = 'queue'` にすると、rebuild は非同期で実行 — 現在のリクエストは Loader から直接データを取得し、バックグラウンドでキャッシュが再構築されます。
`rebuild.strategy = 'callback'` を使うと独自の callable を指定できます（例: Horizon 優先キューへのディスパッチ）。詳細は [Cache Architecture](docs/cache-architecture-ja.md) を参照。

---

## 対応クエリメソッド

Kura は Laravel QueryBuilder の約99メソッドを実装しています。完全なリストは [Laravel Builder カバレッジ](docs/laravel-builder-coverage-ja.md) を参照してください。

### WHERE

`where`, `orWhere`, `whereNot`, `whereIn`, `whereNotIn`, `whereBetween`, `whereNull`, `whereNotNull`, `whereLike`, `whereColumn`, `whereAll`, `whereAny`, `whereNone`, `whereExists`, `whereFilter`, `whereRowValuesIn` など。

### ORDER BY / LIMIT / ページネーション

`orderBy`, `orderByDesc`, `latest`, `oldest`, `inRandomOrder`, `limit`, `offset`, `paginate`, `simplePaginate`

### 取得 / 集計

`get`, `first`, `find`, `sole`, `value`, `pluck`, `cursor`, `count`, `min`, `max`, `sum`, `avg`, `exists`

---

## ドキュメント

| ドキュメント | 説明 |
|---|---|
| [バージョン管理](docs/version-management-ja.md) / [English](docs/version-management.md) | バージョン切り替え、CSV/DB ドライバー、Middleware |
| [インデックスガイド](docs/index-guide-ja.md) / [English](docs/index-guide.md) | インデックス種類、チャンク分割、composite index、範囲クエリ |
| [クエリレシピ](docs/query-recipes-ja.md) / [English](docs/query-recipes.md) | よくあるクエリパターンと例 |
| [キャッシュアーキテクチャ](docs/cache-architecture-ja.md) / [English](docs/cache-architecture.md) | 内部設計: TTL、Self-Healing、rebuild フロー |
| [概要](docs/overview-ja.md) / [English](docs/overview.md) | クラス構成と責務 |
| [Laravel Builder カバレッジ](docs/laravel-builder-coverage-ja.md) / [English](docs/laravel-builder-coverage.md) | 完全な API 対応表 |
| [トラブルシューティング](docs/troubleshooting-ja.md) / [English](docs/troubleshooting.md) | APCu 問題・クエリ遅延・マルチサーバー構成 |

## 設定

全オプションは [`config/kura.php`](config/kura.php) にあります。以下は完全なリファレンスです。

```php
return [
    // APCu キープレフィックス
    'prefix' => 'kura',

    // キャッシュ種別ごとの TTL（秒）
    'ttl' => [
        'ids'        => 3600,   // 再構築トリガー — 失効すると次のクエリが rebuild を実行
        'record'     => 4800,   // ids より長め（rebuild をまたいでレコードを保持するため）
        // 'index'   => 省略すると ids の TTL（jitter 込み）と同じ値が使われる（推奨）
        //              ids とインデックスが同時に失効するため、ids が存在するのに
        //              インデックスキーが欠損するウィンドウが生じない
        'ids_jitter' => 600,    // ids と index TTL にランダム 0〜N 秒を加算（サンダリングハード対策）
    ],

    // rebuild ロックの TTL（秒）。Loader 実行時間の 1.5〜2倍を目安に設定。
    'lock_ttl' => 60,

    // rebuild 戦略
    'rebuild' => [
        // 'sync'     — 現在のリクエスト内で同期 rebuild
        // 'queue'    — Laravel キュージョブで非同期 rebuild
        // 'callback' — 独自 callable を使用（下記 'callback' に設定）
        'strategy' => 'sync',

        // strategy = 'callback' のとき必須
        // callable(\Kura\CacheRepository $repository): void
        'callback' => null,

        // strategy = 'queue' のときに使用
        'queue' => [
            'connection' => null,   // キュー接続名（null = デフォルト）
            'queue'      => null,   // キュー名（null = デフォルト）
            'retry'      => 3,      // 最大試行回数
        ],
    ],

    // バージョン解決
    'version' => [
        'driver' => 'database',             // 'database' or 'csv'

        // database ドライバー
        'table'   => 'reference_versions',
        'columns' => [
            'version'      => 'version',      // バージョン文字列のカラム名
            'activated_at' => 'activated_at', // アクティベーションタイムスタンプのカラム名
        ],

        // csv ドライバー
        'csv_path' => '',                   // versions.csv の絶対パス

        // 全バージョン行を APCu にキャッシュする秒数（0 = キャッシュなし、毎リクエスト読み込む）
        'cache_ttl' => 300,
    ],

    // キャッシュウォームエンドポイント
    'warm' => [
        'enabled'           => false,
        'token'             => env('KURA_WARM_TOKEN', ''),  // Bearer トークン（必須）
        'path'              => 'kura/warm',                 // URL パス
        'controller'        => \Kura\Http\Controllers\WarmController::class,
        'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
    ],

    // CSV 自動発見
    'csv' => [
        'base_path'     => '',     // テーブルサブディレクトリをスキャンするディレクトリ
        'auto_discover' => false,  // CSV テーブルの自動登録を有効化
    ],

    // テーブル単位のオーバーライド（primary_key および/または ttl）
    'tables' => [
        // 'products' => [
        //     'primary_key' => 'product_code',  // 主キーのオーバーライド（デフォルト: 'id'）
        //     'ttl' => ['record' => 7200],       // 特定の TTL をオーバーライド
        // ],
    ],
];
```

## ベンチマーク

### 計測環境

| | |
|---|---|
| ホスト | Apple M4 Pro |
| ランタイム | Docker linux/aarch64 |
| PHP | 8.4.19 |
| APCu | 5.1.28（`apc.shm_size=256M`）|
| イテレーション数 | シナリオごとに 500 回 |
| 指標 | p95 レイテンシ |

### データセット

以下のスキーマ・インデックスを持つ商品レコードで計測:

| カラム | 型 | カーディナリティ |
|---|---|---|
| `id` | int | ユニーク（1〜N） |
| `name` | string | ユニーク |
| `country` | string | 5値（JP / US / GB / DE / FR）均等分布 |
| `category` | string | 10値（electronics / clothing / …）均等分布 |
| `price` | float | 200種類（1.99〜200.99、循環）|
| `active` | bool | 67% true / 33% false |

宣言インデックス: `country`、`price`、`country|category`（composite）

### 結果（p95 レイテンシ）

| シナリオ | 1K件 | 10K件 | 100K件 |
|---|---|---|---|
| `find($id)` — 1件取得 | **0.9 µs** | **1.0 µs** | **0.9 µs** |
| `where('country','JP')` — インデックス `=`（ヒット率 20%）| **139 µs** | **1.3 ms** | **15 ms** |
| `where('country','JP')->where('category','electronics')` — composite index（ヒット率 2%）| **101 µs** | **951 µs** | **11 ms** |
| `whereBetween('price', [50,100])` — 範囲インデックス（ヒット率 25%）| **180 µs** | **1.7 ms** | **18 ms** |
| `where('country','JP')->orderBy('price')` — インデックスウォーク | **186 µs** | **1.6 ms** | **21 ms** |
| `where('active', true)` — 非インデックス全走査（ヒット率 67%）| 483 µs | 6.2 ms | 53 ms |
| `get()` — 全件取得 | 387 µs | 3.7 ms | 39 ms |
| キャッシュ構築（`rebuild()`）| 3.0 ms | 11.1 ms | 117 ms |

インデックスを活用するクエリ（**太字**）は同サイズの全走査より 3〜5倍高速。
100K件でもインデックスありなら 21 ms 以内で応答。
`orderBy` にインデックス列を指定すると APCu に保存済みのソート済みインデックスをそのまま走査（インデックスウォーク）— PHP ソート不要。

> Docker 環境で `php benchmarks/benchmark.php` を実行して再現できます。

---

## キャッシュウォーミング

デプロイ後（トラフィックが来る前に）HTTP 経由で APCu キャッシュを事前構築できます。

### warm エンドポイントの有効化

```php
// config/kura.php
'warm' => [
    'enabled' => true,
    'token'   => env('KURA_WARM_TOKEN', ''),
],
```

### Bearer トークンの生成

```bash
php artisan kura:token          # トークンを生成して .env に書き込む
php artisan kura:token --show   # 現在のトークンを表示
php artisan kura:token --force  # 確認なしで上書き
```

### エンドポイント

**`POST /kura/warm`** — 全登録テーブルのキャッシュを再構築

```bash
# 同期（strategy=sync、デフォルト）
curl -X POST https://your-app.com/kura/warm \
     -H "Authorization: Bearer $KURA_WARM_TOKEN"

# キュー経由で非同期（strategy=queue）
curl -X POST https://your-app.com/kura/warm \
     -H "Authorization: Bearer $KURA_WARM_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"strategy": "queue"}'
# → 202 {"batch_id": "abc123"}
```

**`GET /kura/warm/status/{batchId}`** — 非同期 rebuild の進捗確認

```bash
curl https://your-app.com/kura/warm/status/abc123 \
     -H "Authorization: Bearer $KURA_WARM_TOKEN"
# → {"id":"abc123","totalJobs":3,"pendingJobs":1,"failedJobs":0,"finished":false}
```

### APCu なしでのテスト

テストや CI では `ApcuStore` の代わりに `ArrayStore` を使用できます:

```php
use Kura\Store\ArrayStore;

$store = new ArrayStore;
$repository = new CacheRepository(table: 'products', primaryKey: 'id', store: $store, loader: $loader);
```

`ArrayStore` は PHP 配列で動作するため、APCu 拡張は不要です。

---

## ライセンス

MIT
