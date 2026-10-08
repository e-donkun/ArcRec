# ianseo-lite

ScorekeeperNG(ISK-NG)で得点を入力できる、**予選だけ・1セッションだけ**の記録会向けの簡易システムです。
本家 ianseo とは独立していて(本家の DB・`Common/` などには依存しません)、このフォルダだけで動きます。

**置き場所は `Modules/Custom/ianseo-lite/`** です。ianseo 本体の更新は `Modules/Custom/` を触らないので(`Update/FileList.php`)、
更新しても消えません。メニューの「モジュール」→「ianseo-lite（簡易記録会）」から管理画面を開けます。
カスタムモジュール管理(`Modules/Custom/ModuleManager`)の有効/無効の切り替えにも対応します(無効にすると管理画面と端末 API が 403 になります)。
`data/`(SQLite の DB)と `config.local.php` はモジュールの更新でも残る設定にしています(`module.json` の `keep`)。
単体で別の場所に置いて動かすこともできます。

- バックエンド: **PHP + SQLite**(MySQL・Apache の専用設定は不要)
- 入力クライアント: 既存の **ScorekeeperNG**(モード: **Lite**)
- 対象: 屋外 **36射 / 72射**(6射 x 6・12エンド)、屋内 **30射 / 60射**(3射 x 10・20エンド)。1距離・1セッション
- 出力: **スコアカード PDF**(白紙/得点入り)、**結果 PDF**、結果・全矢の CSV

本家 ianseo の ISK-NG Lite モードの通信(`Api/ISK-NG/v1/*`)を、予選に必要な部分だけ SQLite 上に再実装しています。
個人戦/団体戦のマッチ・エリミネーション・ゲート・Pro/Live モードは対象外です。

## 必要なもの

- PHP 7.4 以上(動作確認は 8.3)。拡張: `pdo_sqlite`, `mbstring`, `zlib`
- タブレットとサーバーが同じネットワーク(Wi-Fi)にあること

## 起動

ianseo と同じ Web サーバー(XAMPP など)に置いてある場合は、そのまま
`http://<サーバー>/<ianseoのパス>/Modules/Custom/ianseo-lite/admin/` を開きます(ianseo のメニューからもリンクしています)。

ianseo を使わず単体で試すだけなら、PHP の組み込みサーバーで足ります。

```sh
cd Modules/Custom/ianseo-lite
php -S 0.0.0.0:8080 -t .
# → http://<このPCのIP>:8080/admin/
```

最初に管理パスワードを決めます。データベースは `data/lite.sqlite` に作られます。
`data/` は Web から見えないようにしてください(Apache は同梱の `data/.htaccess` で拒否。
nginx は `location ~ /ianseo-lite/data/ { deny all; }`)。
場所を変えたいときは、環境変数 `LITE_DATA_DIR` か、`config.local.php` を使います。

```php
<?php // config.local.php (任意)
return ['data_dir' => '/var/lib/ianseo-lite', 'timezone' => 'Asia/Tokyo', 'admin_password' => '固定したいパスワード'];
```

## 使い方

1. **競技設定** — 大会名・大会コード・形式(36/72/30/60)・PIN(任意)を入力し、部門(リカーブ男子など)を作る。
2. **選手** — 名簿を CSV(UTF-8 / Shift_JIS)で取り込むか1人ずつ追加。「的番号の割り当て」で A〜D を自動配置。
3. **スコアカード PDF** — 選手ページ・入力状況ページから出力。各カードに「その的のQR」を印刷します。
4. **端末接続** — 表示される QR を ScorekeeperNG で読み取る(モード **Lite**)。QR が使えない場合は、アプリの設定にサーバーURL・大会コード・PIN を手入力します。
   的のQR(またはアプリ内の的番号選択)でその的の選手が表示され、入力した得点が随時サーバーに保存されます。
5. **入力状況** — 進行の確認、手入力による訂正(履歴が残ります)、スコアカードの「確定」(以後その選手は端末から更新できません)。
6. **結果** — 部門別の順位(合計 → 10+X → X)。結果ページは**ログイン不要で閲覧可**(30秒ごとに自動更新)。結果 PDF / CSV を出力。

### サーバーURL について

QR に入る URL は「ブラウザで開いている URL」から推測します。`localhost` で開いている場合はタブレットから届かないので、
競技設定の「サーバーURL」にLAN上のアドレス(例 `http://192.168.0.10:8080/`)を設定してください。
端末の接続先 API は `<サーバーURL>Api/ISK-NG/` です(サーバーURL直下への POST も同じ API として受け付けます)。

## 得点データの扱い

- 矢は 1 文字 1 本の `arrowstring` で保存します(本家と同じ: `A`=M, `B`〜`J`=1〜9, `L`=10, `K`=X。小文字は疑義)。
- 端末から届いたエンドは、**空でないエンドだけを丸ごと置き換え**ます。端末が再同期で空の状態を送っても、他のエンドは消えません。
- 端末は **自分に割り当てられた的** の選手にしか書き込めません。確定済みのスコアカードは更新を拒否します。
- 端末・管理画面からの更新は、エンド単位で `score_log` に残ります(訂正ページで確認できます)。
- バックアップは `data/lite.sqlite`(と同じ場所の `-wal`/`-shm`)をコピーするだけです。

## セキュリティ

- 管理画面はパスワード1つ + CSRF トークン。**初回アクセスの人がパスワードを決める**ので、公開前に必ず設定を済ませてください(`config.local.php` の `admin_password` で固定も可)。
- 端末の登録は大会コードと(設定していれば)PIN が必要です。登録済みの端末だけが API を使えます。
- 結果ページ・結果 PDF は公開、それ以外は管理者のみです。インターネットに直接公開する場合は HTTPS のリバースプロキシ配下に置いてください。

## テスト

```sh
php tests/run.php        # (このフォルダで実行) 一時 DB で ScorekeeperNG との通信・採点・順位・PDF 生成を確認
```

## 構成

```
Modules/Custom/ianseo-lite/
├─ module.json            カスタムモジュール管理用のパッケージ情報
├─ menu.php               ianseo のメニュー「モジュール」にリンクを追加
├─ index.php              入口(端末のPOSTはAPI、ブラウザは管理画面へ)
├─ Api/ISK-NG/index.php   ScorekeeperNG の接続先
├─ admin/                 管理画面(ホーム/競技設定/選手/入力状況/結果/端末接続/PDF/CSV)
├─ src/                   ロジック(Db, Settings, Entries, Scoring, Results, IskApi, Pdf ...)
├─ lib/tcpdf/             PDF 生成(TCPDF 同梱。LGPL)と日本語フォント(Komorebi Gothic)
├─ assets/                的面画像、アプリ用言語ファイル(ja/en)
├─ tools/gen_targets.php  的面定義を ianseo の Install/install.sql から生成する開発用スクリプト(実行時は不要)
├─ tests/run.php
└─ data/                  SQLite DB(Git 管理外)
```

## 制限事項・注意

- ScorekeeperNG の通信仕様は本家 ianseo のソース(`Api/ISK-NG`)から再現したものです。**実機アプリでの確認は、本番前に必ずリハーサルで行ってください**
  (接続→的選択→入力→確定→PDF の一連)。アプリの版によって挙動が異なる可能性があります。
- 「QR 読み取りの強制」「署名の正規表現」「GPS 設定」など Pro/Live 向けの端末設定には対応していません。
- 予選のみ・1距離・1セッションです。複数距離やマッチ戦が必要なら本家 ianseo を使ってください。
- ライセンスは本家 ianseo に準じます(このフォルダの `LICENSE.txt`)。TCPDF は LGPL(`lib/tcpdf/LICENSE.TXT`)。
# ianseo-lite
