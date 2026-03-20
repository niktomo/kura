# Kura TODO

## ベンチマークから判明した知見

- **chunk は常に逆効果**: 均一・偏り分布ともに no chunk が faster。M4 Pro の APCu fetch (0.7µs) が速すぎて、複数 fetch + デシリアライズのオーバーヘッドがスキップの節約を常に上回る
- **chunk_size: null がベスト**。機能は残すが「現実的には効果なし」と明記すべき
- **orderBy + paginate at 100K は 500ms+**: 全件ソートコストが支配的。100K 件に orderBy+paginate は設計を再考すべき
- **whereIn (non-indexed) はリスト長に無関係**: 全走査して in_array するため 10件も 1000件も同コスト
- **OR (片方 non-indexed) は full scan に落ちる**: 性能崖あり、設計時に注意

---

## 設計検討中（未決定）

### A. テーブル登録の自動化

**A-1. CSV 自動発見** ✅ 完了
- `kura.csv.base_path` 配下のサブディレクトリを自動スキャン → テーブルとして登録
- `data.csv` が存在するディレクトリのみ CsvLoader として自動登録
- `config/kura.php` に `auto_discover: true` を追加するだけで動く
- primary key が `id` 以外のテーブルだけ `config.tables` で上書き
- `KuraManager::register()` が factory closure を受け付けるように変更（遅延初期化）
- 新テーブルディレクトリ追加時は PHP 再起動が必要（スキャンは起動時1回）
- data.csv 更新は `kura:rebuild` が必要（自動検知なし）

**A-2. DB テーブルの config 駆動登録**（別途検討）
- config にテーブル名を列挙するだけで EloquentLoader を自動構築
- スキーマ（カラム型・インデックス）は別途検討（SHOW COLUMNS / SHOW INDEXES は依存が強い）

```php
// 理想の config（A-1 実装後）
'csv' => [
    'base_path'     => storage_path('reference'),
    'auto_discover' => true,
],
'tables' => [  // 例外だけ書く
    'products' => ['primary_key' => 'product_code'],
],
```

**未解決（A-2 向け）:**
- DB 型マッピング（`varchar`, `tinyint(1)`, `decimal` → `int/float/bool/string`）
- DB インデックスを全部キャッシュするか選択するか

---

### B. `indexes.csv` サポート ✅ 完了
CsvLoader が `indexes.csv` を読めるようになった。CSV ディレクトリが自己完結。

```csv
columns,unique
name,false
code,true
country|type,false   ← composite は | 区切り
```

- コンストラクタ引数が明示的に渡された場合はそちらが優先（後方互換）
- ファイル未存在 → インデックスなし
- インスタンス内キャッシュ済み（毎回ファイルを読まない）
- `KuraManager::register()` が factory closure を受け付けるように変更（大量テーブル登録時の起動コスト削減）

**依存:** A（自動発見）を実装するなら B は必須 → 充足済み。

---

### C. meta キー廃止 + chunk 廃止 ✅ 完了

**実装:** meta キーを完全廃止し、chunk 機能も削除。

| 情報 | 移動先 |
|---|---|
| columns（型） | `LoaderInterface::columns()` — クエリ時に Loader から取得 |
| composites リスト | `LoaderInterface::indexes()` — CacheProcessor が遅延解決 |
| chunk min/max | **廃止**（ベンチマークで常に逆効果と判明） |

**変更内容:**
- `StoreInterface` / `ApcuStore` / `ArrayStore` から `getMeta()` / `putMeta()` 削除
- `CacheRepository::rebuild()` から chunk・meta 書き込みを削除
- Phase 2（idx/cidx 書き込み）をロック内に移動 → thundering herd 解消
- `IndexResolver` コンストラクタに `$indexedColumns` / `$compositeNames` を注入（meta 参照を排除）
- `CacheProcessor::resolveIndexDefs()` で `repository.loader().indexes()` から遅延導出・インスタンスキャッシュ
- APCu キーが 5種類 → 4種類（meta 廃止）

**ベンチマーク根拠:** chunk は均一・偏り分布ともに no chunk より常に遅い（M4 Pro で APCu fetch 0.7µs が速すぎて複数 fetch のオーバーヘッドがスキップ節約を常に上回る）。

---

---

### E. Self-Healing 強化 ✅ 完了

**index TTL を ids TTL とデフォルト一致させる**
- 以前: ids=3600, index=4800（独立した値）
- 変更後: `$indexTtl = $ttl['index'] ?? $idsTtl` — 明示指定がない限り ids と同じ値
- APCu の通常 evict パスでは ids と index が同タイミングで失効 → rebuild トリガーが揃う

**`IndexInconsistencyException`（index キー欠損の検出）**
- meta が「カラム X にインデックスあり」と言っているのに、APCu の `idx:X` キーが欠損 → 旧動作: サイレントにフルスキャン落ち（rebuild なし）
- 新動作: `IndexInconsistencyException`（`extends CacheInconsistencyException`）を投げる
- `select()` の既存 catch が捕まえて `dispatchRebuild()` + `cursorFromLoader()` へフォールバック
- 対象箇所: 単一 index / BETWEEN / chunked index / composite index / ordered walk（`walkIndex()`）

---

## 実装予定（設計確定済み）

### D. パフォーマンス改善（実装済み ✅）
- [x] orderBy の二重 fetch 解消 — `RecordCursor` に `idsMap` を渡してインライン整合性チェック
- [x] 部分的 AND index 解決 — 非 index の AND 条件をスキップ（WhereEvaluator が後で評価）
- [x] orderBy インデックスウォーク — APCu 保存済みのソート済みインデックスを直接走査、PHP ソート不要
  - `CacheProcessor::cursorFromCacheIndexWalked()` + `walkIndex()` を追加
  - 単一カラム orderBy + そのカラムがインデックス済みの場合に自動適用
  - `where(...)->orderBy('price')->get()` at 100K: 47ms → 18ms（2.6x）
  - `orderBy('price')->paginate(20)` at 100K: 244ms → 4ms（60x）— limit で早期終了

---

## ドキュメント・品質（残り）

優先度順：

- [x] CHANGELOG に最初のリリースを切る（現在全て `[Unreleased]`）
- [x] README にバッジ追加（CI / Packagist / PHP バージョン / ライセンス）
- [x] トラブルシューティングガイドを追加（APCu が有効にならない / キャッシュが消えた / apc.enable_cli など）
- [x] SECURITY.md を追加（脆弱性報告窓口）
- [x] CSV の空セル = NULL として扱う旨を明示（`data.csv` セクション）
- [x] インデックス戦略ガイドを追加（単カラム vs composite の選び方・カーディナリティの考え方・composite が逆効果になるケース）
- [x] 全タスク完了後：ベンチマーク再計測 → README・docs のパフォーマンス数値を更新

- [x] `README: ⚠️ This package is in development` など開発ステータスを明示
- [x] CONTRIBUTING.md に PR/Issue 手順・ブランチ戦略を追加
- [x] パフォーマンスの裏付け（ベンチマーク結果を README に追加、`benchmarks/benchmark.php` 追加）

---

## ドキュメント修正（完了 ✅）

- [x] ロック機構の説明修正（TTL はクラッシュ安全策、明示削除は finally ブロック）
- [x] 「ids のみ再構築」の誤記削除（rebuild は常に全件フラッシュ）
- [x] 擬似コードのシグネチャ修正（`cursor(Builder $builder)` → 実際の引数列）
- [x] Self-Healing サマリ修正（meta なし → 全再構築。index/meta 再構築ではない）
- [x] `strategy: callback` の誤解を解消（config 値ではなく `app->extend()` で登録）
- [x] 「Loader は別パッケージ」の誤記修正（src/Loader/ に含まれる）
- [x] `indexes.csv` を概要ドキュメントから削除（現時点では存在しない）
- [x] CsvLoader の読み込みルール修正（`version = X` → `version <= X`）
- [x] スケール目安セクション追加（推奨 ~100K 件/テーブル）
- [x] README Requirements を composer.json と一致させる（PHP ^8.2, Laravel ^10+）
- [x] Warm API / kura:token を README に追加（エンドポイント仕様・curl 例）
- [x] ArrayStore によるテスト方法を README に追加
- [x] APCu 制約を cache-architecture.md に追加（プロセスローカル・マルチサーバー・shm_size）
- [x] Dockerfile の `katana.ini` → `kura.ini` に修正（旧プロジェクト名の残滓）
