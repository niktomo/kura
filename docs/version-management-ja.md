> English version: [version-management.md](version-management.md)

# バージョン管理

## 概要

Kura はリファレンスデータのバージョンを管理し、ダウンタイムなしでデータを切り替えることができます。バージョンが変わるとキャッシュキーが自動的に変わり、旧キャッシュは TTL で自然に消滅します。

```
v1.0.0 アクティブ → キャッシュキー: kura:products:v1.0.0:*
         ↓ バージョン切り替え
v2.0.0 アクティブ → キャッシュキー: kura:products:v2.0.0:*
                     v1.0.0 のキーは TTL で自然消滅（手動クリーンアップ不要）
```

---

## バージョンドライバー

Kura は **CSV** と **Database** の2つのドライバーに対応しています。

### CSV ドライバー

バージョン情報を `versions.csv` ファイルで管理します。全テーブルで共通のファイルを使用します。

```php
// config/kura.php
'version' => [
    'driver'    => 'csv',
    'csv_path'  => base_path('data/versions.csv'),
    'cache_ttl' => 300,
],
```

**versions.csv:**
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
3,v3.0.0,2025-01-01 00:00:00
```

`activated_at <= 現在時刻` の中で最も新しいバージョンが選択されます。

各テーブルはディレクトリで管理し、`version` カラムを持つ単一の `data.csv` を用意します:

```
data/
├── versions.csv
├── stations/
│   ├── table.yaml
│   └── data.csv          # version カラム必須
└── lines/
    ├── table.yaml
    └── data.csv
```

CsvLoader は `version が NULL`（全バージョン共通データとして常にロード）または `version <= 現在のバージョン`（過去・現在のバージョン行）を読み込みます。`version > 現在のバージョン` の行はスキップされます（まだアクティブでない未来のデータ）。

**data.csv の例:**
```csv
id,name,prefecture,version
1,東京,Tokyo,
2,大阪,Osaka,
3,札幌,Hokkaido,v1.0.0
4,福岡,Fukuoka,v2.0.0
```

この例では 1・2 行目（version = null）は全バージョンでロード。3 行目（v1.0.0）は activeVersion >= v1.0.0 のときにロード。4 行目（v2.0.0）は activeVersion >= v2.0.0 のときにロードされ、activeVersion = v1.0.0 ではスキップされます。

### Database ドライバー

バージョン情報をデータベーステーブルで管理します。

```php
// config/kura.php
'version' => [
    'driver'    => 'database',
    'table'     => 'reference_versions',
    'columns'   => [
        'version'      => 'version',
        'activated_at' => 'activated_at',
    ],
    'cache_ttl' => 300,
],
```

**マイグレーション例:**
```php
Schema::create('reference_versions', function (Blueprint $table) {
    $table->id();
    $table->string('version')->unique();
    $table->timestamp('activated_at');
    $table->timestamps();
});
```

**シード例:**
```php
DB::table('reference_versions')->insert([
    ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
    ['version' => 'v2.0.0', 'activated_at' => '2024-06-01 00:00:00'],
]);
```

CSV と同じ選択ルール: `activated_at <= 現在時刻` で最新のものが使われます。

---

## バージョンリゾルバー

### VersionResolverInterface

```php
interface VersionResolverInterface
{
    public function resolve(): ?string;
}
```

### 実装一覧

| クラス | 役割 | 用途 |
|---|---|---|
| `CsvVersionResolver` | `versions.csv` から全行を読み込む | CSV のみのデプロイ |
| `DatabaseVersionResolver` | DB テーブルから全行を読み込む | DB ベースのデプロイ |
| `CachedVersionResolver` | 全行を APCu にキャッシュ；`resolve()` 時に `now()` でフィルタ | 本番環境（上記いずれかをラップ） |

### CachedVersionResolver

`CachedVersionResolver` は `VersionsLoaderInterface`（`CsvVersionResolver` または `DatabaseVersionResolver`）をラップします。**全バージョン行** を APCu にキャッシュし、`resolve()` のたびに現在時刻でフィルタします:

```
APCu ミス → DB/CSV から全行ロード → APCu に cache_ttl 秒保存
APCu ヒット → activated_at <= now() でフィルタ → 最新の一致バージョンを返却
```

```php
use Illuminate\Database\ConnectionInterface;
use Kura\Version\CachedVersionResolver;
use Kura\Version\DatabaseVersionResolver;

// KuraServiceProvider が $app['db']->connection() で自動バインドする
$inner = new DatabaseVersionResolver(
    connection: $app['db']->connection(),
    table: 'reference_versions',
);
$resolver = new CachedVersionResolver($inner, new \Kura\Version\SystemClock(), ttl: 300);

// 初回: DB から全行読み取り、APCu に保存
// 5分以内の後続呼び出し: APCu の行を now() でフィルタ
$version = $resolver->resolve();
```

- **PHP var キャッシュ**: リクエスト中のバージョンを固定（Octane 安全）
- **APCu キャッシュ**: 全バージョン行をクロスリクエストで保持；`cache_ttl` 秒ごとに更新
- **DB/CSV**: APCu ミス時のみアクセス

新バージョンの `activated_at` が到来すると、`resolve()` は自動的にそのバージョンを返します — 全行がキャッシュ済みで毎回フィルタするため、キャッシュの無効化は不要です。

`KuraServiceProvider` が config に基づいて適切なリゾルバーを自動的に作成・バインドします。

---

## バージョンオーバーライド

### Artisan コマンド

```bash
# 特定バージョンで rebuild（activated_at を無視）
php artisan kura:rebuild --reference-version=v2.0.0
```

### プログラム内

```php
use Kura\Facades\Kura;

// 以降のすべての操作でバージョンをオーバーライド
Kura::setVersionOverride('v2.0.0');
```

### HTTP ヘッダー

`X-Reference-Version` ヘッダーでリクエスト単位のバージョン固定が可能です（下記 Middleware 参照）。

---

## Middleware

`examples/KuraVersionMiddleware.php` にサンプルを提供しています:

```php
class KuraVersionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $serverVersion = $this->resolver->resolve();

        $response = $next($request);
        $response->headers->set('X-Reference-Version', $serverVersion);

        $clientVersion = $request->header('X-Reference-Version');
        if ($clientVersion !== null && $clientVersion !== $serverVersion) {
            $response->headers->set('X-Reference-Version-Mismatch', 'true');
        }

        return $response;
    }
}
```

この Middleware は:
1. サーバー側の現在のバージョンを解決
2. レスポンスヘッダーに付与
3. クライアント/サーバー間のバージョン不一致を検知

`bootstrap/app.php` で登録:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [KuraVersionMiddleware::class]);
})
```

---

## バージョンデプロイフロー

```
1. 新しいデータを準備
   └─ v2.0.0 用の CSV ファイルまたは DB レコードを更新

2. バージョンを登録
   └─ reference_versions に行を追加: v2.0.0, activated_at = 未来日時
   └─ または versions.csv に行を追加

3. アクティベーション
   └─ activated_at 到達 → VersionResolver が v2.0.0 を返し始める

4. キャッシュ切り替え
   └─ 新しいクエリは kura:*:v2.0.0:* キーを使用
   └─ キャッシュミス → Self-Healing が v2.0.0 キャッシュを再構築
   └─ 旧 v1.0.0 キーは TTL で自然消滅（手動クリーンアップ不要）

5. （オプション）事前ウォームアップ
   └─ php artisan kura:rebuild --reference-version=v2.0.0
   └─ または POST /kura/warm?version=v2.0.0
```

### ベストプラクティス

- **`activated_at` を未来に設定** し、アクティベーション前にキャッシュを事前ウォーム
- **`cache_ttl` を使用** してバージョン変更の伝播速度を制御（デフォルト: 5分）
- **旧バージョンの CSV は TTL 消滅まで保持** — 切り替え中も一時的にサーブされる可能性あり
- **`X-Reference-Version` ヘッダーで監視** — クライアント側でバージョン変更を検知可能

---

## クライアントのバージョン戦略

クライアントがアクティブバージョンをどのように保持・追従するかのパターン。データ更新頻度やスキーマ変更の有無に応じて選択する。

### パターン A — ビルド時に埋め込む

```
CI/CD ビルド時
  └─ versions.csv または API から最新バージョン（例: v2.0.0）を取得
  └─ アプリバイナリにビルド時に埋め込む

クライアント（モバイルアプリ / SPA バンドル）
  └─ 常に送信: X-Reference-Version: v2.0.0（固定）
  └─ X-Reference-Version-Mismatch: true を受信したら
       └─ アプリアップデートを促す
```

**向いているケース:** スキーマや UI の変更を伴う更新（カラム追加・廃止など）。バージョン更新 = アプリリリースと対応するため、安全で予測しやすい。

---

### パターン B — 起動時またはMismatch時に取得 ⭐ 推奨

```
アプリ起動時
  └─ GET /api/version  →  "v2.0.0"
  └─ メモリ / ローカルストレージに保持

各リクエスト
  └─ 送信: X-Reference-Version: v2.0.0
  └─ 受信: X-Reference-Version: v3.0.0（サーバーが更新済み）
         + X-Reference-Version-Mismatch: true
       └─ バージョンを再取得 → v3.0.0 に更新
       └─ 同じリクエストを v3.0.0 で再送（アプリ更新不要）
```

**向いているケース:** データ内容のみの変更（行追加・値更新）でスキーマ変更がない場合。アプリリリースなしにクライアントが自動追従できる。

---

### パターン C — アプリバージョンとデータバージョンを連動させる

```
データバージョン v3.0.0 リリース
  └─ アプリ v3.0 も同時リリース（スキーマ・UI 変更をセットで対応）
  └─ アプリ v2.x ユーザーは X-Reference-Version-Mismatch を受信
       └─ 強制アップデートを促す
```

**向いているケース:** データと UI が密結合している場合（新しいデータ項目に対応する新画面が必要など）。

---

### パターンの選び方

| | パターン A | パターン B | パターン C |
|---|---|---|---|
| データのみの更新 | アプリ再ビルドが必要 | ✅ 自動追従 | アプリ再ビルドが必要 |
| スキーマ・UI の変更 | ✅ リリースで管理 | 手動対応が必要 | ✅ リリースで管理 |
| 運用のシンプルさ | 高 | 中 | 高 |
| 推奨シーン | 静的なリファレンスデータ | 頻繁に更新されるデータ | データとアプリが密結合 |

---

## Config リファレンス

```php
'version' => [
    // バージョン解決ドライバー
    'driver' => 'database',       // 'database' or 'csv'

    // Database ドライバー設定
    'table' => 'reference_versions',
    'columns' => [
        'version'      => 'version',       // バージョン文字列のカラム名
        'activated_at' => 'activated_at',   // アクティベーションタイムスタンプのカラム名
    ],

    // CSV ドライバー設定
    'csv_path' => '',  // versions.csv の絶対パス

    // 全バージョン行を APCu にキャッシュする秒数
    // 0 = キャッシュなし（毎リクエスト DB/CSV から読み込む）
    'cache_ttl' => 300,
],
```
