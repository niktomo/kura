# Contributing

## 開発環境

```bash
composer install
```

## コマンド

```bash
composer test        # PHPUnit
composer analyse     # PHPStan (level 8)
composer format      # Pint (Laravel preset)
composer check       # test + analyse
```

## コーディング規約

### 静的解析

- **PHPStan level 8** — `phpstan.neon` で設定済み
- **Pint** — Laravel preset + `not_operator_with_successor_space`（`pint.json`）

### PHP

- `in_array()` は必ず第3引数 `true`（strict mode）を指定する
- PHP 8.4: `fgetcsv` / `fputcsv` は `escape: ''` パラメータを指定する
- NULL の扱いは MySQL セマンティクスに準拠する

### テスト規約

- **Unit Tests**: AAA 形式（`// Arrange`, `// Act`, `// Assert`）
- **Feature Tests**: BDD/Gherkin 形式（`// Given`, `// When`, `// Then` コメント）
- **組み合わせテスト**: PHPUnit `#[DataProvider]`
- **モック**: Mockery（シンプルに、false positive/negative を避ける）
- **アサーションメッセージ**: 全アサーションに「何を検証し、何が期待結果か」のメッセージを付与
- **テスト用 Loader**: `tests/Support/InMemoryLoader` を使用
- **リスト型プロパティ**: `/** @var list<array<string, mixed>> */` を付与

### 命名規約

- "master" は使わない → "reference" を使用
- カラム名: `activated_at`（not `start_at`）
- ヘッダー: `X-Reference-Version`

---

## ブランチ戦略

| ブランチ | 用途 |
|---|---|
| `main` | 安定版。直接 push 禁止。PR のみ |
| `feat/xxx` | 新機能 |
| `fix/xxx` | バグ修正 |
| `docs/xxx` | ドキュメントのみの変更 |
| `refactor/xxx` | 外部仕様を変えないリファクタリング |

- `main` からブランチを切る
- 1ブランチ = 1目的（複数の変更を混在させない）

---

## Issue

### バグ報告

以下を含めてください：

- PHP / Laravel / APCu のバージョン
- 再現できる最小コード
- 期待した動作と実際の動作

### 機能要望

- ユースケースを先に説明する（「〜がしたい」）
- 実装案がある場合は別途記載

---

## Pull Request

1. `main` から `feat/xxx` or `fix/xxx` ブランチを作成
2. 変更を実装し、テストを追加
3. CI がすべて通ることを確認（`composer check`）
4. PR を作成し、以下を記載：
   - **何を変えたか**（変更内容の要約）
   - **なぜ変えたか**（動機・背景）
   - **どのようにテストしたか**

### PR のルール

- テストなしの機能追加は受け付けない
- PHPStan level 8 エラーがある状態でマージしない
- 1 PR = 1目的（レビューしやすいサイズに保つ）
- `main` への force push 禁止
