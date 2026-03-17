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

各テーブルはディレクトリで管理し、バージョンごとにデータCSV（差分ではなく全件スナップショット）を用意します:

```
data/
├── versions.csv
├── stations/
│   ├── defines.csv
│   ├── v1.0.0.csv
│   └── v2.0.0.csv
└── lines/
    ├── defines.csv
    ├── v1.0.0.csv
    └── v2.0.0.csv
```

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

| リゾルバー | ソース | 用途 |
|---|---|---|
| `CsvVersionResolver` | `versions.csv` ファイル | CSV のみのデプロイ |
| `DatabaseVersionResolver` | DB `reference_versions` テーブル | DB ベースのデプロイ |
| `CachedVersionResolver` | デコレータ — APCu + PHP var でキャッシュ | 本番環境（上記いずれかをラップ） |

### CachedVersionResolver

任意のリゾルバーをラップして DB/CSV への繰り返しアクセスを回避します:

```php
use Kura\Version\CachedVersionResolver;
use Kura\Version\DatabaseVersionResolver;

$inner = new DatabaseVersionResolver(/* ... */);
$resolver = new CachedVersionResolver($inner, cacheTtl: 300);

// 初回: DB から読み取り、APCu + PHP var にキャッシュ
// 5分以内の後続呼び出し: キャッシュから返却
$version = $resolver->resolve();
```

- **PHP var キャッシュ**: 即時（同一リクエスト内）
- **APCu キャッシュ**: サブミリ秒（クロスリクエスト、同一 SAPI 内）
- **DB/CSV**: 両方のキャッシュがミスした場合のみ（`cache_ttl` 秒ごと）

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

    // 解決されたバージョンを APCu にキャッシュする秒数
    // 0 = キャッシュなし（毎回解決）
    'cache_ttl' => 300,
],
```
