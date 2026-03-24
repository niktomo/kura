> English version: [design-constraints.md](design-constraints.md)

# 設計制約と拡張ポイント

Kura のスコープは意図的に絞られています。中心となる操作は次の2つです。

- **QueryBuilder 互換のフィルタリング** — アプリケーションが呼ぶ fluent API
- **インデックスを使ったルックアップ** — APCu バックの二分探索と composite hashmap による高速な絞り込み

それ以外は Interface/Closure で差し替え可能か、正確性を保証するために設計上固定されています。

---

## 拡張ポイント

### 1. データソース — `LoaderInterface`

`LoaderInterface` を実装すれば、REST API・S3・別のデータベース・独自フォーマットなど任意のバックエンドを使えます。

```php
use Kura\Loader\LoaderInterface;

class MyApiLoader implements LoaderInterface
{
    public function load(): \Generator
    {
        foreach ($this->api->fetchAll() as $row) {
            yield $row;  // 連想配列を yield する
        }
    }

    public function columns(): array
    {
        // カラム名 => 型 ('int', 'float', 'string', 'bool')
        return ['id' => 'int', 'code' => 'string', 'price' => 'float'];
    }

    public function indexes(): array
    {
        return [
            ['columns' => ['code'], 'unique' => true],
            ['columns' => ['category', 'code'], 'unique' => false],  // composite
        ];
    }

    public function primaryKey(): string
    {
        return 'id';
    }

    public function version(): string|int|\Stringable
    {
        return $this->api->currentVersion();
    }
}
```

`load()` の規約:
- `yield` を使うこと（ジェネレーター）— 配列を return しない
- yield する値は文字列キーの連想配列であること
- 主キーカラムが全レコードに含まれていること
- カラム名と型は `columns()` の戻り値と一致すること

`indexes()` の規約:
- composite index のカラム順は **カーディナリティが低い順**（例: `['country', 'city']`、逆は不可）
- composite index の各カラムには単一カラムインデックスが自動生成される — 個別に宣言する必要はない

### 2. バージョン解決 — `VersionResolverInterface`

`AppServiceProvider` でカスタム実装を bind します:

```php
use Kura\Contracts\VersionResolverInterface;

$this->app->bind(VersionResolverInterface::class, MyVersionResolver::class);
```

リゾルバーはリクエストごとに1回呼ばれ、その結果はリクエスト中 PHP メモリにキャッシュされます。

### 3. Rebuild のディスパッチ — `strategy: callback`

Horizon の優先キューやマルチテナントのディスパッチなど、独自のルーティングが必要な場合:

```php
// config/kura.php
'rebuild' => [
    'strategy' => 'callback',
    'callback' => static function (\Kura\CacheRepository $repository): void {
        dispatch(new \App\Jobs\RebuildReferenceJob($repository->table()))
            ->onQueue('high');
    },
],
```

クロージャーはキャッシュミスを検出したリクエスト内で同期的に呼ばれます。処理は素早く完了させてください（dispatch だけして、実処理はジョブ内で行う）。

### 4. テーブル単位の TTL — `config tables`

```php
'tables' => [
    'products' => [
        'ttl' => ['record' => 7200],
    ],
],
```

テーブル単位でオーバーライドできるのは `ttl` のみです。prefix・rebuild strategy などはグローバル設定のみです。

---

## 固定仕様

以下の仕様は変更できません。正確性と Self-Healing の根幹を支えているためです。

### APCu キー形式

```
kura:{prefix}:{table}:{version}:pks
kura:{prefix}:{table}:{version}:record:{id}
kura:{prefix}:{table}:{version}:idx:{column}
kura:{prefix}:{table}:{version}:cidx:{col1|col2}
kura:{table}:lock
```

`pks` キーはテーブルのキャッシュ存在を示すシグナルです。Self-Healing はこのキーの消失を検知してトリガーされます。外部からこれらのキーに書き込まないでください。

### 全件ロード（差分更新なし）

rebuild がトリガーされると、Kura は常にテーブル全件をロードして書き込みます。個別レコードの挿入・更新・削除 API はありません。これは意図的な設計です — 部分的な状態はクエリの不整合につながります。

### Self-Healing は常に有効

クエリ時に `pks` がなければ、Kura は自動的に rebuild をトリガーします。これを無効化する手段はありません。デプロイ中に rebuild を防ぎたい場合は、トラフィックを受ける前に `kura:rebuild` Artisan コマンドで事前ウォームアップしてください。

### インデックス種別: unique / non-unique / composite

インデックス種別は `Loader` が宣言し、ロード時に構築されます。追加インデックスをランタイムで登録したり、ロード後にインデックス種別を変更する API はありません。インデックス構成を変えるには `LoaderInterface::indexes()` の戻り値を更新して rebuild をトリガーしてください。

---

## データライフサイクルの制約

Kura は **バージョンでゲートされたデータ** を前提に設計されています。データの変更・スキーマの変更はすべてバージョン切り替えと紐付いています。これは削除操作に重要な意味を持ちます。

### レコード削除

Kura はソース側の削除を検知しません。あるバージョンがアクティブな間に DB や CSV からレコードを削除しても、キャッシュは TTL が切れるか明示的に rebuild するまでそのレコードを返し続けます。

安全なパターンは **2フェーズ削除** です。

```
v1.0.0  レコードが存在し、アプリが参照している
v2.0.0  アプリがそのレコードを参照しない（ロジックレベルで除外）
v3.0.0  データソースからレコードを物理削除する
```

中間バージョンを挟むことで、キャッシュが切り替わる前にレコードがソースから消えることを防ぎます。

### スキーマ変更（カラムの追加・削除）

カラムの追加・削除はレコード変更より影響が大きい操作です。APCu に保存されるレコードは rebuild 時点のカラム構造で固定されるため、バージョンの途中でスキーマを変更すると `columns()` と APCu 上のレコードに不整合が生じます。

**安全な方法はメンテナンスウィンドウを設けることのみです。**

1. トラフィックを止める（またはフィーチャーフラグでテーブルを隠す）
2. データソースにスキーマ変更を適用する
3. バージョンを上げて `php artisan kura:rebuild` を実行する
4. トラフィックを再開する

スキーマ変更にローリングアップグレードの手段はありません。ローリングアップグレードが安全なのは、データのみの変更（行追加・値更新・カラム変更なし）のバージョン切り替えに限られます。

---

## QueryBuilder 互換の規約

Kura は Laravel の `Illuminate\Database\Query\Builder` から約99のメソッドを実装しています。以下の規約がスコープを定義しています。

**対象** — フラットなインメモリレコードに対して意味のある操作:
- すべての `where*` バリアント（等値・範囲・NULL・LIKE・IN・BETWEEN・複合条件）
- `orderBy`, `limit`, `offset`, `paginate`, `simplePaginate`
- `get`, `first`, `find`, `sole`, `value`, `pluck`, `cursor`
- `count`, `min`, `max`, `sum`, `avg`, `exists`

**対象外** — リレーショナルデータベースを必要とする操作:
- `join`, `leftJoin`, `rightJoin`, `crossJoin`
- `whereHas`, `with`（Eloquent リレーション）
- `toSql`, `dd`, `dump`（クエリコンパイル）
- `lock`, `lockForUpdate`, `sharedLock`
- `union`, `unionAll`
- **他のテーブルを参照する** `where` クロージャー（クロステーブルのサブクエリはインメモリのフラットデータに対して意味をなさない。同一テーブル内の条件グルーピングを行うクロージャーは対応している）

対象外のメソッドを呼ぶと `\BadMethodCallException` がスローされます。

---

## メモリモデル

本番環境での想定外を防ぐために、Kura のメモリ使用の仕組みを理解しておいてください。

### APCu 共有メモリ（`apc.shm_size`）

全データはここに保存されます。テーブル×バージョンごとに以下が格納されます。

| キー種別 | メモリ使用量 |
|---|---|
| `pks` | レコード数に比例（hashmap `[id => true]`） |
| `record:{id}` | レコード1件ごと（連想配列） |
| `idx:{column}` | インデックスカラムごと（value→ids のソート済み配列） |
| `cidx:{col1\|col2}` | composite index ごと（値ペア→ids の hashmap） |

`apc.shm_size` は全テーブル×アクティブバージョンが収まるサイズに設定してください。rebuild は旧バージョンの TTL が切れる前に新バージョンを書き込むため、ピーク時は通常の約2倍のメモリを使います。

### リクエストごとの PHP メモリ

レコードは一括で PHP メモリに読み込まれることはありません。ジェネレーターベースの走査で APCu から1件ずつ取得します。リクエストごとのフットプリントは:

- クエリで解決した ID セットのインデックスデータ（インデックスなしの全走査では大きくなりえる）
- 走査中のレコード1件分

**実運用上の制限**: テーブルの `pks` リストや単一インデックスが1つの APCu エントリに収まらないほど大きい場合、APCu の書き込みに失敗します。その場合はインデックス設定でチャンク分割を有効にしてください。

---

## コントリビューション: 新しい `where` メソッドの追加

新しい `where*` メソッドを追加するには:

1. **`ReferenceQueryBuilder`** — メソッドを追加し、既存の条件と同じ構造で `$this->wheres` に条件を格納する
2. **`CacheProcessor::compilePredicate()`** — 新しい条件種別の評価ロジックを追加する（全走査フォールバックで使用）
3. **`CacheProcessor::resolveIds()`** — インデックスで高速化できる条件なら、インデックスルックアップのパスをここに追加する
4. **テスト** — インデックスパスと全走査フォールバックの両方をカバーするテストを追加する

`Illuminate\Database\Query\Builder` に存在しないメソッドは追加しないでください — API は Laravel の QueryBuilder の完全な部分集合である必要があります。

---

## コントリビューション: インデックス構造

インデックスデータは `LoaderInterface::indexes()` の戻り値を元に `CacheRepository::rebuild()` が構築します。新しいインデックス種別のプラグイン Interface はありません。独自のルックアップ戦略が必要な場合は、カスタムの `CacheProcessor` サブクラスで実装してください（実験的。公式サポート外）。
