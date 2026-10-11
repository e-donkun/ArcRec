# ArcRec

- **アーチェリー記録会管理システム**である。
- Ianseo互換の、学校・チーム内の記録会を想定している。
- ScorekeeperNG（ISK-NG）の Lite モードで得点を入力できる。
- WA形式の予選（1セッション、1〜2距離）に対応する。
- Outdoor は1距離36射、Indoor は1距離30射である。
- スコアカードPDF・結果PDF・CSVを出力できる。

## 動作環境

- PHP 7.4以上（PHP 8.3で動作確認済み。セキュリティ更新が提供されているPHPを推奨）
- PHP拡張: `pdo_sqlite`、`mbstring`、`zlib`
- ScorekeeperNGを使う場合、タブレットからサーバーへ接続できるネットワーク
- Webサーバーから `data/`（または設定したデータ保存先）へ書き込めること

## ダウンロードと共通の設置手順

1. [リリース一覧](https://github.com/e-donkun/arcrec/releases)から `ArcRec-バージョン.zip` をダウンロードする。「Source code」のZIPではなく、リリースに添付されたZIPを使うこと。
2. ZIPを展開し、含まれる `ArcRec/` フォルダをWebサーバーの公開フォルダ内に配置する。配置先は `index.php` がURLから開ける場所とする。
3. Webサーバーの設定で、PHP 7.4以上と `pdo_sqlite`、`mbstring`、`zlib` が使えることを確認する。
4. PHPから `data/` に書き込めるようにする。Apacheでは同梱の `data/.htaccess` がWebからのアクセスを拒否する。nginxでは後述の設定を行う。
5. ブラウザーで配置先を開く。管理画面はURL末尾に `admin/` を付けて開く。
6. 初回に全体管理者パスワードを設定し、すぐに安全なパスワードへ変更・管理する。

記録会データは既定で `data/` に保存される。公開フォルダ外に保存する場合は、ArcRecの配置先に `config.local.php` を作成し、実際のパスに書き換える。

```php
<?php
return [
    'data_dir' => '/path/to/arcrec-data',
];
```

nginxでは、配置先に合わせて `data/` へのHTTPアクセスを拒否する。たとえばArcRecを `/arcrec/` に置いた場合は、サーバー設定に次を追加する。

```nginx
location ^~ /arcrec/data/ {
    deny all;
}
```

## OS・ホスティング別の設置手順

### Windows（XAMPP）

1. [Apache Friends](https://www.apachefriends.org/download.html)からXAMPPを入手してインストールする。
2. ArcRecのZIPを展開し、`ArcRec/` フォルダをXAMPPの `htdocs/` に配置する（例: `C:\xampp\htdocs\ArcRec`）。
3. XAMPP Control PanelでApacheを起動する。PHPの設定で必要な拡張が有効であることを確認し、変更した場合はApacheを再起動する。
4. `http://localhost/ArcRec/` を開く。

XAMPPはローカルでの試用向けである。インターネットに公開する用途では、公開用Webサーバーのセキュリティ設定を行うこと。

### macOS（MAMP）

1. [MAMP](https://www.mamp.info/en/downloads/)を入手してインストールする。
2. ArcRecのZIPを展開し、`ArcRec/` フォルダをMAMPのドキュメントルート（通常は `htdocs/`）に配置する。
3. MAMPでWebサーバーを起動し、使用するPHPで必要な拡張が有効であることを確認する。
4. MAMPに表示されるローカルURLに `/ArcRec/` を付けて開く。

### Linux（自分で管理するサーバー）

1. ディストリビューションのパッケージ管理機能でApacheまたはnginx、PHP、SQLite用PDO、mbstringを導入する。PHPのzlib拡張も必要である。
2. ArcRecのZIPをサーバー上で展開するか、展開した `ArcRec/` フォルダを転送し、Webサーバーの公開フォルダに配置する。
3. PHP-FPMまたはWebサーバーから `data/` に書き込めるよう、所有者・権限を設定する。書き込み権限を全利用者に与えないこと。
4. Apacheでは同梱の `.htaccess` が有効であることを確認する。nginxでは前述の `data/` 拒否設定を追加する。
5. 必要なPHP拡張を確認し、配置先のURLを開く。

### さくらのレンタルサーバ

1. コントロールパネルでPHPのバージョンを選択する。利用するプラン・PHP環境で `pdo_sqlite`、`mbstring`、`zlib` が使えることも確認する。
2. PCで配布ZIPを展開する。さくらのファイルマネージャーではZIPを展開できないため、FTPソフトで `ArcRec/` フォルダを公開フォルダ（通常 `www/`）へアップロードする。
3. PHPからArcRecの `data/` に書き込めることを確認する。可能であれば公開フォルダ外の保存先を用意し、上記の `config.local.php` で `data_dir` を指定する。
4. `https://ドメイン/ArcRec/`（配置場所に応じて変更）を開き、初期設定を行う。

利用できるPHPのバージョンや設定は契約プラン等で異なる。最新情報は[さくらのPHP利用案内](https://help.sakura.ad.jp/rs/2252/)と[PHPバージョン変更の案内](https://help.sakura.ad.jp/rs/2241/)を確認すること。FTP・ファイルマネージャーは[公式サポート情報](https://help.sakura.ad.jp/rs/2195/)を参照。

## 初期設定と使い方

- 配布ZIPには、サンプル記録会（屋外・屋内）が含まれる。どちらのパスワードも `sample` であり、動作確認用である。実際の参加者情報や得点は入力しないこと。
- 管理画面で全体管理者としてログインし、「新しい記録会を作成」から記録会を作成する。
- 記録会ごとに個別パスワードがあり、全体管理者パスワードでも各記録会を開ける。
- 記録会設定でOutdoor / Indoor、距離数、的の種類を選択する。
- 部門にはコード・名称・並び順を設定する。名簿CSVに未登録の部門がある場合は、先に部門を登録する。
- 参加者は画面から登録するか、UTF-8 / Shift_JISのCSVから一括で取り込む。
- ScorekeeperNGは管理画面の「端末設定」にあるQRコードを読み取る。QRを使わない場合は、表示されたサーバーURLと記録会コードを入力する。
- 「点数管理」で入力状況を確認し、「全員を確定」するとScorekeeperNGからの追加・変更入力が停止する。
- 管理画面の「結果」では順位を確認し、結果PDF / CSVを出力できる。結果を公開にした記録会は公開ページに表示される。

## セキュリティとバックアップ

- サンプル記録会のパスワードは公開されている。サンプルは操作確認だけに使い、実データを登録しないこと。
- 全体管理者パスワードと記録会パスワードには、推測されにくい異なるパスワードを設定する。
- `data/` をWebから直接アクセスできないようにする。可能な環境では公開フォルダ外への保存を推奨する。
- バックアップではデータ保存先にある記録会データ（`competitions/`）と管理者設定ファイルを保存する。

## 制限事項

- WA形式の予選（1〜2距離、1セッション）に対応する。マッチ戦、3距離以上、部門ごとに異なる的面には対応しない。
- ScorekeeperNGの実機接続を含め、本番前にリハーサルで一連の操作を確認すること。

## ライセンス

ArcRecはGNU GPL v3で配布する。ライセンス全文は `LICENSE.txt`、含まれるファイルの表示は `NOTICE.md` を参照。無保証である。
