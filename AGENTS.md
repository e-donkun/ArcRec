# 開発ガイド（生成AI向け）

このファイルは ArcRec の開発・保守を行う生成AI向けの案内です。利用者向けの設置・操作説明は `README.md` を参照してください。

## 技術と構成

- PHP 7.4 以上を対象とする PHP アプリケーションです。データベースは SQLite（PDO）で、主な必要拡張は `pdo_sqlite`、`mbstring`、`zlib` です。
- `index.php` がブラウザー向け公開ページと ScorekeeperNG API の入口です。`Api/ISK-NG/index.php` も API の入口として使われます。
- `admin/` は管理画面、`src/` はアプリケーションロジック、`src/targets/` は端末へ送る的面画像、`lib/tcpdf/` は同梱の PDF ライブラリです。
- `data/` は実行時データ用で、通常は Git 管理外です。記録会ごとに SQLite DB を分けます。テストは `LITE_DB` または `LITE_DATA_DIR` を設定して一時領域を利用します。
- `tools/` は的面データやサンプル記録会の生成に使う開発用ツールです。`samples/` には配布用サンプル記録会の DB があります。

## ロジックの目安

- `src/Db.php`: SQLite 接続、スキーマ作成・更新、現在の DB 選択。
- `src/Competitions.php`, `Auth.php`, `Settings.php`, `Categories.php`: 記録会、認証、設定、部門。
- `src/Entries.php`, `Devices.php`, `IskApi.php`: 参加者・端末・ScorekeeperNG API。
- `src/Scoring.php`, `Results.php`, `Pdf.php`: 採点、順位・集計、PDF 出力。
- 管理画面の各機能は `admin/` 内のページに分かれています。表示と処理の既存の責務分担に合わせ、変更範囲を小さく保ってください。

## テスト

変更後はリポジトリのルートで全テストを実行します。

```sh
php tests/run.php
```

`tests/run.php` は通信・参加者・採点・順位・PDF などを確認し、`two_distances.php` と `multi.php` も別プロセスで実行します。テストは一時 SQLite DB を使います。テストを追加・変更した場合は、既存の実行方法に合わせてください。

## 実装時に守ること

- 既存の PHP 構文・ライブラリを使い、不要な依存パッケージや新しいビルド・テスト基盤を追加しないでください。
- ScorekeeperNG API のレスポンスは項目名だけでなく値の型も互換性に関わります。文字列と数値を不用意に変えず、関連テストで確認してください。
- 各記録会は独立した DB を持ちます。選択中の記録会と端末リクエストが指す記録会を混同せず、複数記録会を扱う機能では `tests/multi.php` のテストを確認してください。
- 得点は距離ごとの `arrowstring` に保存されます。エンド更新、確定済みスコア、的番号の移動に関わる変更では、既存の整合性と履歴記録を維持してください。
- DB の変更は `src/Db.php` のスキーマ作成・更新処理と既存データへの影響を確認してください。
- `data/`、`config.local.php`、秘密情報や実データをコミットしないでください。変更ファイルに秘密情報がないことを確認してください。
- 利用者向けの設置や操作に影響する場合は `README.md` も更新してください。開発・テスト手順はこのファイルに記載してください。
