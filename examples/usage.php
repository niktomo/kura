<?php

/**
 * Kura 使い方サンプル
 *
 * このファイルは実行用ではなく、使い方のリファレンスです。
 */

// ============================================================================
// 1. バージョン解決とテーブル登録
// ============================================================================

// config/kura.php でバージョン解決を設定:
//
//   'version' => [
//       'driver'    => 'database',          // 'database' or 'csv'
//       'table'     => 'reference_versions',
//       'columns'   => ['version' => 'version', 'activated_at' => 'activated_at'],
//       'csv_path'  => '',                  // CSV driver の場合のパス
//       'cache_ttl' => 300,                 // APCu キャッシュ秒数（5分）
//   ],
//
// KuraServiceProvider が VersionResolverInterface を自動バインドする。
// → DB/CSV への問い合わせは cache_ttl 間隔でキャッシュされる。

// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\DB;
use Kura\Contracts\VersionResolverInterface;
use Kura\KuraManager;
use Kura\Loader\EloquentLoader;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;

class AppServiceProvider
{
    public function boot(KuraManager $kura, VersionResolverInterface $resolver): void
    {
        // バージョン解決（起動時に1回だけ）
        // VersionResolverInterface は KuraServiceProvider で config から自動バインド済み
        $version = $resolver->resolve() ?? 'v1.0.0';

        // --- DB テーブルの登録 ---
        $kura->register('products', new EloquentLoader(
            query: Product::query(),
            columns: ['id' => 'int', 'name' => 'string', 'price' => 'int', 'category' => 'string', 'country' => 'string'],
            indexDefinitions: [
                ['columns' => ['category'], 'unique' => false],
                ['columns' => ['country'], 'unique' => false],
                ['columns' => ['country', 'category'], 'unique' => false],  // composite index
            ],
            version: $version,
        ));

        $kura->register('active_users', new EloquentLoader(
            query: User::where('active', true),
            columns: ['id' => 'int', 'name' => 'string', 'email' => 'string', 'role' => 'string'],
            indexDefinitions: [
                ['columns' => ['role'], 'unique' => false],
                ['columns' => ['email'], 'unique' => true],
            ],
            version: $version,
        ));

        // --- CSV テーブルの登録 ---
        // ディレクトリ構成:
        //   data/
        //     versions.csv              ← id,version,activated_at
        //     countries/
        //       defines.csv             ← column,type,description
        //       v1.0.0.csv              ← データスナップショット
        //
        // $csvResolver = new CsvVersionResolver(base_path('data/versions.csv'));
        // $kura->register('countries', new CsvLoader(
        //     tableDirectory: base_path('data/countries'),
        //     resolver: $csvResolver,
        //     indexDefinitions: [
        //         ['columns' => ['code'], 'unique' => true],
        //     ],
        // ));
    }
}

// ============================================================================
// 2. クエリ実行（Controller やどこからでも）
// ============================================================================

use Kura\Facades\Kura;

// --- 基本クエリ ---

// 全件取得
$products = Kura::table('products')->get();

// 条件付き
$jpProducts = Kura::table('products')
    ->where('country', 'JP')
    ->get();

// 比較演算子
$expensive = Kura::table('products')
    ->where('price', '>=', 1000)
    ->orderBy('price', 'desc')
    ->get();

// --- find: O(1) 直読み ---

$product = Kura::table('products')->find(42);

// --- first / value ---

$cheapest = Kura::table('products')
    ->orderBy('price')
    ->first();

$cheapestName = Kura::table('products')
    ->orderBy('price')
    ->value('name');

// --- 集約 ---

$count = Kura::table('products')->where('country', 'JP')->count();
$total = Kura::table('products')->sum('price');
$avg = Kura::table('products')->avg('price');
$min = Kura::table('products')->min('price');
$max = Kura::table('products')->max('price');

// --- pluck ---

$names = Kura::table('products')->pluck('name');           // ['Widget', 'Gadget', ...]
$nameById = Kura::table('products')->pluck('name', 'id');     // [1 => 'Widget', 2 => 'Gadget']

// --- whereIn / whereBetween ---

$selected = Kura::table('products')
    ->whereIn('category', ['electronics', 'books'])
    ->get();

$midRange = Kura::table('products')
    ->whereBetween('price', [500, 2000])
    ->get();

// --- whereNull ---

$active = Kura::table('products')
    ->whereNull('deleted_at')
    ->get();

// --- 複雑な WHERE（Closure でグループ化）---

// WHERE (country = 'JP' OR country = 'US') AND price >= 500
$result = Kura::table('products')
    ->where(function ($q) {
        $q->where('country', 'JP')
            ->orWhere('country', 'US');
    })
    ->where('price', '>=', 500)
    ->get();

// WHERE (country = 'JP' AND category = 'electronics')
//    OR (country = 'US' AND price < 1000)
$result = Kura::table('products')
    ->where(function ($q) {
        $q->where('country', 'JP')
            ->where('category', 'electronics');
    })
    ->orWhere(function ($q) {
        $q->where('country', 'US')
            ->where('price', '<', 1000);
    })
    ->get();

// WHERE NOT (category = 'discontinued')
$result = Kura::table('products')
    ->whereNot(function ($q) {
        $q->where('category', 'discontinued');
    })
    ->get();

// --- whereAny / whereNone ---

// WHERE (name = 'Widget' OR category = 'Widget')
$result = Kura::table('products')
    ->whereAny(['name', 'category'], 'Widget')
    ->get();

// --- whereFilter: PHP クロージャで任意条件 ---

$result = Kura::table('products')
    ->whereFilter(fn ($r) => str_starts_with($r['name'], 'A'))
    ->get();

// --- exists ---

$hasJP = Kura::table('products')->where('country', 'JP')->exists();

// --- ページネーション ---

$page = Kura::table('products')
    ->orderBy('name')
    ->paginate(perPage: 15, page: 1);

// --- cursor: 省メモリ Generator ---

foreach (Kura::table('products')->where('country', 'JP')->cursor() as $product) {
    // 1件ずつ処理（メモリに全件載せない）
}

// --- limit / offset ---

$top3 = Kura::table('products')
    ->orderBy('price', 'desc')
    ->limit(3)
    ->get();

// ============================================================================
// 3. DI で使う（Facade を使わない場合）
// ============================================================================

class ProductController
{
    public function index(KuraManager $kura)
    {
        return $kura->table('products')
            ->where('country', 'JP')
            ->orderBy('price')
            ->get();
    }
}

// ============================================================================
// 4. Artisan コマンドでキャッシュウォームアップ
// ============================================================================

// 全テーブル rebuild（Loader のバージョンを使用）
// php artisan kura:rebuild

// 特定テーブルのみ
// php artisan kura:rebuild products active_users

// バージョンを明示して rebuild（デプロイ時に推奨）
// php artisan kura:rebuild --reference-version=v2.0.0

// デプロイスクリプト例:
//   php artisan kura:rebuild --reference-version=$(get_latest_version)
//   → Pod 起動直後の初回リクエスト前にキャッシュをウォーム

// ============================================================================
// 5. バージョンフロー
// ============================================================================

// 1. reference_versions テーブル（DB or CSV）
//    ┌──────────────────────────────────────────────┐
//    │ id │ version │ activated_at                       │
//    │  1 │ v1.0.0  │ 2024-01-01 00:00:00           │
//    │  2 │ v2.0.0  │ 2025-06-01 00:00:00 ← active  │
//    └──────────────────────────────────────────────┘
//
// 2. Pod 起動時
//    VersionResolver::resolve() → "v2.0.0"
//    ↓
//    Loader に version を渡して register
//    ↓
//    artisan kura:rebuild --reference-version=v2.0.0
//    ↓
//    APCu keys: kura:products:v2.0.0:ids
//               kura:products:v2.0.0:record:1
//               kura:products:v2.0.0:meta
//
// 3. リクエスト時
//    Client → X-Reference-Version: v2.0.0
//    ↓
//    Middleware で照合 → 一致 → キャッシュからクエリ実行
//    ↓
//    バージョン不一致 → X-Reference-Version-Mismatch: true
//
// 4. バージョンアップ時
//    reference_versions に v3.0.0 を INSERT（activated_at = 未来日時）
//    ↓
//    activated_at に達する → VersionResolver が v3.0.0 を返し始める
//    ↓
//    v2.0.0 のキーはヒットしなくなる → Self-Healing で v3.0.0 rebuild

// ============================================================================
// 6. Self-Healing
// ============================================================================

// rebuild を明示的に呼ばなくても、初回クエリ時に自動で Loader から読み込む。
// APCu が evict されても、次のクエリで自動復旧する。
// → 手動での rebuild は「ウォームアップ」用。通常運用では不要。
