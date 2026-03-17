> English version: [README.md](README.md)

# Kura

**Kura**（蔵）は、リファレンスデータを APCu にキャッシュし、**Laravel QueryBuilder 互換の API** で検索できる Laravel パッケージです。

CSV や DB からデータを一度読み込み、APCu に保存し、いつもの fluent API でクエリ — **実行時の DB クエリは不要**です。インデックスによる高速化でサブミリ秒のレスポンスを実現します。

## なぜ Kura？

- **馴染みのある API** — `where`, `orderBy`, `paginate`, `find`, `count`, `sum` — Laravel の QueryBuilder と同じ
- **サブミリ秒の読み取り** — APCu 共有メモリ、ネットワーク往復なし
- **低メモリ使用量** — Generator ベースの走査、全件をメモリに載せない
- **スマートインデックス** — 二分探索インデックスで範囲クエリ対応、composite index hashmap で O(1) 複合カラム検索、大規模データセット向けの自動チャンク分割
- **Self-Healing** — キャッシュが消えても自動再構築 — アプリケーションからは常に完全なデータが見える
- **バージョン管理** — DB や CSV でリファレンスデータのバージョンをシームレスに切り替え

## 要件

- PHP ^8.4
- Laravel ^12.0
- APCu 拡張 (`pecl install apcu`)

## インストール

```bash
composer require tomonori/kura
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
        'cache_ttl' => 300,                             // 解決結果を5分キャッシュ
    ],

    // Rebuild 戦略 — キャッシュが消えたときの動作
    'rebuild' => [
        'strategy' => 'sync',  // 'sync'（Queue 不要）or 'queue'（本番推奨）
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
    └── v1.0.0.csv         # データスナップショット（バージョンごとに全件）
```

**versions.csv** — アクティブなバージョンを制御:
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
```

`activated_at <= 現在時刻` で最も新しいバージョンが使われます。
各バージョンの CSV は**差分ではなく全件スナップショット**です。

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
```

サポートする型: `int`, `float`, `bool`, `string`

**stations/v1.0.0.csv** — データ本体:
```csv
id,name,prefecture,city,lat,lng,line_id
1,東京,Tokyo,千代田区,35.6812,139.7671,1
2,渋谷,Tokyo,渋谷区,35.6580,139.7016,1
3,新宿,Tokyo,新宿区,35.6896,139.7006,1
4,大阪,Osaka,北区,34.7024,135.4959,2
5,なんば,Osaka,中央区,34.6629,135.5013,3
```

#### パターン B: Database（Eloquent）

CSV 不要 — データベースから直接読み込み:

```php
use Kura\Loader\EloquentLoader;

$loader = new EloquentLoader(
    query: Station::query(),
    columns: [
        'id' => 'int', 'name' => 'string', 'prefecture' => 'string',
        'city' => 'string', 'lat' => 'float', 'lng' => 'float',
        'line_id' => 'int',
    ],
    indexDefinitions: [
        ['columns' => ['prefecture'], 'unique' => false],
        ['columns' => ['line_id'], 'unique' => false],
    ],
    version: 'v1.0.0',
);
```

### 3. テーブルを登録する

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
        indexDefinitions: [
            ['columns' => ['prefecture'], 'unique' => false],
            ['columns' => ['line_id'], 'unique' => false],
        ],
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
kura:stations:v1.0.0:meta                   # カラム定義 + インデックス構造
kura:stations:v1.0.0:record:1               # 1レコード
kura:stations:v1.0.0:idx:prefecture         # 検索インデックス（単カラム）
kura:stations:v1.0.0:idx:price:0            # チャンクインデックス（大規模データ）
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

## 設定

全オプションは [`config/kura.php`](config/kura.php) を参照してください。テーブル単位のオーバーライドも可能です:

```php
'tables' => [
    'stations' => [
        'ttl' => ['record' => 7200],
        'chunk_size' => 10000,  // 大きなインデックスをチャンクに分割
    ],
],
```

## ライセンス

MIT
