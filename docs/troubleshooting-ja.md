> English version: [troubleshooting.md](troubleshooting.md)

# トラブルシューティング

## APCu が動かない

### 症状

- キャッシュが構築されない（常に Loader にフォールバックする）
- `apcu_store` / `apcu_fetch` が `false` を返す
- クエリが遅い（常に DB/CSV を叩いている）

### 原因と対処

**1. APCu 拡張がインストールされていない**

```bash
php -m | grep apcu
```

表示されない場合：

```bash
pecl install apcu
```

`php.ini` に追加：

```ini
extension=apcu.so
```

**2. CLI で APCu が無効（`apc.enable_cli=0`）**

APCu はデフォルトで CLI 無効です。artisan コマンド（`kura:rebuild`）やテストに影響します。

```ini
; php.ini または専用 ini ファイル（例: /etc/php/8.x/cli/conf.d/20-apcu.ini）
apc.enable_cli=1
```

動作確認：

```bash
php -r "var_dump(apcu_store('test', 1)); var_dump(apcu_fetch('test'));"
# bool(true) bool(true) — 正常
# bool(false) bool(false) — APCu 無効
```

**3. php-fpm / Web サーバーで APCu が有効になっていない**

APCu はプロセスローカルです。PHP-FPM ワーカーはそれぞれ独立した APCu プールを持ちます。`apc.enable_cli=1` は CLI のみに影響し、Web プロセスは別の ini を参照します。

FPM プールの ini を確認してください：

```bash
php-fpm -m | grep apcu
```

**4. 共有メモリが不足している（`apc.shm_size`）**

デフォルトは `32M` です。大きなデータセットではサイレントに容量超過し、新しいエントリがキャッシュされなくなります。

```ini
apc.shm_size=256M
```

現在の使用状況を確認：

```bash
php -r "print_r(apcu_sma_info());"
# avail_mem が 0 に近い → shm_size を増やす
```

---

## データを変更してもキャッシュが更新されない

Kura はデータ変更を自動検知しません。手動で rebuild をトリガーする必要があります：

```bash
# 全テーブルを再構築
php artisan kura:rebuild

# 特定テーブルのみ
php artisan kura:rebuild --table=products
```

HTTP warm エンドポイントを設定している場合：

```bash
curl -X POST https://your-app.com/kura/warm \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"strategy":"queue"}'
```

---

## サーバー再起動後にキャッシュが消える

APCu は共有メモリに保存され、**再起動後は消えます**。これは仕様です。

Kura の Self-Healing が自動対応します：再起動後の最初のクエリで `ids` キーの欠損を検知し、Loader から自動再構築します。手動対応は不要です。

起動時にプリウォームしたい場合は、デプロイスクリプトやスーパーバイザーのフックから `kura:rebuild` を呼び出してください。

---

## `IndexInconsistencyException` が発生する

**原因：** `ids` キーが存在するにもかかわらず、インデックスの APCu キー（`idx:*` や `cidx:*`）が欠損している状態です。メモリ圧迫により `ids` より先に特定のエントリが evict された場合に発生します。

**Kura の動作：** 例外を自動で捕捉し、rebuild ジョブをディスパッチして、現在のリクエストは Loader にフォールバックします。ユーザーにエラーは表示されません。

**頻発する場合：** `apc.shm_size` を増やすか、テーブルの TTL を下げて全キーが同タイミングで期限切れになるようにしてください。

---

## `CacheInconsistencyException` が発生する

**原因：** `ids` リストに存在する ID の `record:{id}` キーが欠損している状態です（`ids` と `record` が部分的に evict されてズレた）。

**Kura の動作：** 同上 — 自動再構築 + Loader フォールバック。

---

## クエリが予想外に遅い

**確認1：インデックスが使われているか？**

`LoaderInterface::indexes()` で宣言したカラムのみインデックスが作られます。未宣言のカラムへのクエリは常に全走査です。

```php
public function indexes(): array
{
    return [
        ['columns' => ['country'], 'unique' => false],
    ];
}
```

**確認2：OR の片方が非インデックスだと全走査になる**

```php
// 全走査 — 'active' は非インデックスのため OR ブランチがインデックス解決不可
->where('country', 'JP')->orWhere('active', true)
```

**確認3：結果件数が多い場合は常に遅い**

インデックスは候補を絞るためのものです。候補が確定した後は、一致した全レコードを APCu からフェッチします。100K 件中 20% がマッチする場合、20K 件のフェッチは避けられません。

**確認4：非インデックスカラムの `orderBy` は PHP ソートになる**

```php
->orderBy('name')   // PHP ソート（全マッチレコードをソート）
->orderBy('price')  // インデックスウォーク（'price' がインデックス済みなら高速）
```

---

## マルチサーバー構成でキャッシュが一致しない

APCu はプロセス・サーバーごとに独立しています。サーバー間の同期機能はありません。

**推奨対応：**

- データ変更後に **全サーバー** で `kura:rebuild`（または `POST /kura/warm`）を実行する
- 共有キューを使って `strategy=queue` で全サーバーに一斉ディスパッチする

---

## Docker / php-fpm：`apc.enable_cli=1` を設定してもキャッシュが空

CLI と php-fpm は**別プロセス**です。`apc.enable_cli=1` は CLI のみに効きます。Web ワーカーには別の ini が必要です。

FPM の設定にも APCu を追加してください：

```dockerfile
RUN echo "extension=apcu.so" >> /usr/local/etc/php/conf.d/apcu.ini
RUN echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/apcu.ini
RUN echo "apc.shm_size=128M" >> /usr/local/etc/php/conf.d/apcu.ini
```
