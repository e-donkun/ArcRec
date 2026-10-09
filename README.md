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
- 対象: **WA(World Archery)の予選形式**。**1距離または2距離**で、1距離は屋外 **36射**(6射 x 6エンド)・屋内 **30射**(3射 x 10エンド)。
  つまり Outdoor 36射 / Outdoor 36射×2(72射) / Indoor 30射 / Indoor 30射×2(60射) の4形式。1セッション
- 部門: **リカーブ男子・リカーブ女子**のみ(コンパウンドなどは対象外)
- 順位: 全距離の合計 → 10+X の数 → X の数。すべて同じなら同順位(*)で、シュートオフ等で決める
- 出力: **スコアカード PDF**(白紙/得点入り)、**結果 PDF**、結果・全矢の CSV

本家 ianseo の ISK-NG Lite モードの通信(`Api/ISK-NG/v1/*`)を、予選に必要な部分だけ SQLite 上に再実装しています。
個人戦/団体戦のマッチ・エリミネーション・ゲート・Pro/Live モードは対象外です。

## 入れ方(ianseo の Modules/Custom に置く場合)

```sh
cd <ianseo>/Modules/Custom
git clone https://github.com/e-donkun/ianseo-lite
```

更新するときは `git pull`(`data/` と `config.local.php` は Git 管理外なので残ります)。zip で入れる場合は
ianseo の「カスタムモジュール」→「新規追加」から、`ianseo-lite` フォルダの zip を指定します。

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

最初は「大会の一覧」が開きます。同梱の**サンプル大会**(屋外 Outdoor 36射×2 と 屋内 Indoor 30射×2 の2つ。パスワードはどちらも `sample`)で動きを確認できます。
新しい大会は、どれかの大会をパスワードで開いたあと、一覧の「新しい大会を作る」から作ります。

大会のデータは大会ごとに1つの SQLite ファイルで、`data/competitions/<大会コード>.sqlite` に作られます。
`data/` は Web から見えないようにしてください(Apache は同梱の `data/.htaccess` で拒否。
nginx は `location ~ /ianseo-lite/data/ { deny all; }`)。
場所を変えたいときは、環境変数 `LITE_DATA_DIR` か、`config.local.php` を使います。

```php
<?php // config.local.php (任意)
return ['data_dir' => '/var/lib/ianseo-lite', 'timezone' => 'Asia/Tokyo', 'admin_password' => '固定したいパスワード'];
```

## 使い方

画面の作りは ianseo 本体のカスタムモジュール「スマホモード」に合わせています(上に大会名とページ名、下にタブ: ホーム / 選手 / 立順 / 入力 / 結果。
一覧はカード、編集は下から出るシート)。

0. **大会の選択** — 大会を開く・切り替える・新しく作るのは、この画面だけで行います(ホームの右上の「ログアウト」で戻ります。上部バーの大会名からは切り替えません)。
   大会ごとに DB とパスワードが別で、パスワードはその大会の SQLite の中にあります。**大会を開く・切り替えるたびに、その大会のパスワードを入れます**
   (前に開いた大会のパスワードは覚えません)。「新しい大会を作る」(大会名・競技形式・その大会のパスワード)は、どれかの大会を開いているときに出ます。大会コードは自動で付きます。
1. **大会設定** — 大会名と競技形式(Outdoor 36射 / Outdoor 36射×2 / Indoor 30射 / Indoor 30射×2)を選ぶ。距離ごとに名前(2距離の初期値は 前半・後半、1距離は空欄)と的面(屋外: 10リング・6リング、屋内: 40cm 10リング・三つ目)を決める。**結果の公開/非公開**もここで切り替えます(新しい大会は非公開)。大会コードは大会の作成時に自動で付く8桁の16進数で、入力は不要。
2. **選手登録** — スマホモードの参加者一覧と同じ作りです(検索・部門で絞り込み、カードをタップで編集・削除)。「＋追加」で
   - **新しく入力**: 姓・名(横に並んだ入力欄)・所属・部門(男子 / 女子)。所属はこのサーバーの大会から候補が出ます。
   - **過去の大会から**: 所属を選ぶ → その所属だった選手にチェック → まとめて追加(同じ姓名の選手がいれば飛ばします)。
   CSV(UTF-8 / Shift_JIS)でもまとめて登録できます。
3. **立順** — 的番号と位置(A〜D)の設定は別の画面です。的は「1的」「2的」…と表記します。やり方は2つで、どちらでもできます。
   - **割り当て表**(スマホモードの的番号と同じ): 選手をタップして、空き枠をタップすると移動。選手のいる枠をタップすると入れ替え。下の「未割当」からも選べます。
   - **一覧**: 選手ごとのプルダウンで、的番号と位置を選びます。自動割り当て(部門順・登録順)は一覧のほうにあります。
   **点数が入っている選手は動かせません**(端末は「的+位置」で選手を見分けるため)。
   **スコアカード PDF** は選手登録・入力状況のページから出力します。各カードに「その的のQR」を印刷します。
4. **端末接続** — 表示される QR を ScorekeeperNG で読み取る(モード **Lite**)。QR が使えない場合は、アプリの設定にサーバーURLと大会コードを手入力します(どちらも「端末接続」ページに表示されます)。
   的のQR(またはアプリ内の的番号選択)でその的の選手が表示され、入力した得点が随時サーバーに保存されます。
5. **入力状況** — 選手ごとのカードで進行(エンド数・距離ごとの合計)を確認、手入力による訂正(履歴が残ります)、スコアカードの「確定」(以後その選手は端末から更新できません)。
6. **結果** — スマホモードの速報と同じ作り。部門別の順位(合計 → 10+X → X)と的順の切り替え、カードをタップでスコアカード(エンドごとの 計・10+X・X・累計と、距離ごとの小計)を表示。結果 PDF / CSV を出力。
   参加者向けの公開ページは管理画面(`admin/`)ではなく**トップの `results.php`** です。`results.php?c=<大会コード>` でその大会、
   `results.php` だけなら公開している大会の一覧(1つだけならその大会)。ログイン不要で、自動更新があります。
   大会設定で**公開にした大会だけ**見られます(非公開の大会は 403。その大会を開いている管理者は非公開でも確認できます)。

### 端末(ScorekeeperNG)と大会

端末は大会コードで大会を指します。**端末が古い大会のコードで接続してきたら、その大会の DB を読み書きします**(いま管理画面で開いている大会とは関係ありません)。
端末の登録(端末コード・電池・的)も大会ごとの DB に入ります。大会コードのないリクエスト(電池・言語など)は、その端末が最後に通信した大会として扱います。

### サーバーURL について

設定は不要です。QR に入る URL は「ブラウザで開いている URL」から作ります。`localhost` で開いているとタブレットから届かないので、
LAN 上のアドレス(例 `http://192.168.0.10:8080/admin/`)で管理画面を開いてください。
端末の接続先 API は `<サーバーURL>Api/ISK-NG/` です(サーバーURL直下への POST も同じ API として受け付けます)。

## 得点データの扱い

- 矢は距離ごとに 1 文字 1 本の `arrowstring` で保存します(本家と同じ: `A`=M, `B`〜`J`=1〜9, `L`=10, `K`=X。小文字は疑義)。
- 端末から届いたエンドは、**空でないエンドだけを丸ごと置き換え**ます。端末が再同期で空の状態を送っても、他のエンドは消えません。
- 端末は **自分に割り当てられた的** の選手にしか書き込めません。確定済みのスコアカードは更新を拒否します。
- 端末・管理画面からの更新は、エンド単位で `score_log` に残ります(訂正ページで確認できます)。
- バックアップは `data/competitions/` の中の、大会のファイル(`<大会コード>.sqlite`、と同じ場所の `-wal`/`-shm`)をコピーするだけです。
- 1大会だけだった頃の `data/lite.sqlite` があれば、最初のアクセスで `data/competitions/` へ自動で移します(パスワードはそのまま。大会コードが `LITE` なら新しい16進数に変わるので、端末は QR を読み直してください)。

## セキュリティ

- 管理画面のパスワードは**大会ごと**で、その大会の DB の中にあります(ハッシュ)。CSRF トークンも使います。
  新しい大会を作れるのは、どれかの大会をパスワードで開いた人だけです(大会がまったく無いときを除く)。
  `config.local.php` の `admin_password` を設定すると、それは**どの大会も開けるマスターパスワード**になります。
- 選手登録の「過去の大会から」と所属の候補は、このサーバーにあるすべての大会の名簿(姓名・所属・部門だけ)から出します(パスワードを覚えないため)。
- 端末の登録には大会コード(ランダムな8桁の16進数)が必要で、これが合言葉になります。PIN はありません。登録済みの端末だけが API を使えます。
- 結果ページ・結果 PDF/CSV は、大会設定で公開にした大会だけ誰でも見られます。それ以外は管理者のみです。インターネットに直接公開する場合は HTTPS のリバースプロキシ配下に置いてください。

## サーバーへの自動反映(GitHub の main に push → さくらで git pull)

`deploy.php` が GitHub の Webhook を受けて、サーバーのこのフォルダで `git pull --ff-only origin main` を実行します。
Webhook の署名(Secret)を確かめてから動くので、ほかの人がこの URL を叩いても何も起きません。Secret を設定していなければ 404 を返して何もしません。
`data/`(大会の DB)と `config.local.php` は Git の管理外なので、pull しても消えません。

リポジトリは非公開なので、サーバーには**読み取り専用の鍵(Deploy key)**を置きます。以下は さくらのレンタルサーバ の例です
(`<ユーザー名>` はサーバーのアカウント名、`~/www/ianseo-lite` は置き場所。ianseo の `Modules/Custom/ianseo-lite` に置くならそのパス)。

1. **SSH でサーバーに入り、鍵を作る**
   ```sh
   ssh-keygen -t ed25519 -N "" -f ~/.ssh/ianseo-lite_deploy
   cat ~/.ssh/ianseo-lite_deploy.pub
   ```
2. **GitHub に鍵を登録**: リポジトリの Settings → Deploy keys → Add deploy key に、表示された公開鍵を貼る(「Allow write access」は付けない)。
3. **サーバーに clone**(すでに置いてあるフォルダは、`data/` と `config.local.php` を退避してから置き換える)
   ```sh
   GIT_SSH_COMMAND="ssh -i ~/.ssh/ianseo-lite_deploy -o IdentitiesOnly=yes" \
     git clone git@github.com:e-donkun/ianseo-lite.git ~/www/ianseo-lite
   ```
4. **Secret を決めて `config.local.php` に書く**(`openssl rand -hex 20` などでランダムな文字列を作る)
   ```php
   <?php
   return [
       'deploy_secret' => 'ここにランダムな文字列',
       'deploy_ssh_key' => '/home/<ユーザー名>/.ssh/ianseo-lite_deploy',   // 絶対パスで
       // 'deploy_branch' => 'main',   // 省略時 main
   ];
   ```
5. **GitHub に Webhook を登録**: Settings → Webhooks → Add webhook
   - Payload URL: `https://<ユーザー名>.sakura.ne.jp/ianseo-lite/deploy.php`(置き場所に合わせる)
   - Content type: `application/json`
   - Secret: 4. と同じ文字列
   - Which events: **Just the push event**
6. 登録すると GitHub が ping を送ります。Webhook の「Recent Deliveries」が 200(`pong`)なら設定は完了です。
   以後 main に push すると自動で pull され、結果は「Recent Deliveries」の応答と `data/deploy.log` に残ります。

うまくいかないとき:
- 403 `Bad signature` … GitHub と `config.local.php` の Secret が違う。
- 500 で `Permission denied (publickey)` … Deploy key の登録漏れか、`deploy_ssh_key` のパス違い(鍵のパーミッションは 600)。
- 500 で `Not possible to fast-forward` … サーバー側のファイルを直接編集している。`git status` で確かめ、`git checkout -- <ファイル>` で戻す。
- 500 `Cannot run git` … PHP から外部コマンドを実行できない環境。その場合は、さくらのコントロールパネルの「CRON設定」で
  `cd ~/www/ianseo-lite && GIT_SSH_COMMAND="ssh -i ~/.ssh/ianseo-lite_deploy -o IdentitiesOnly=yes" git pull --ff-only -q origin main`
  を数分おきに実行する方法に切り替えてください(Webhook は不要)。

## テスト

```sh
php tests/run.php        # (このフォルダで実行) ScorekeeperNG との通信・採点・順位・PDF・複数大会・的番号の移動を確認
```

## 構成

```
Modules/Custom/ianseo-lite/
├─ module.json            カスタムモジュール管理用のパッケージ情報
├─ menu.php               ianseo のメニュー「モジュール」にリンクを追加
├─ index.php              入口(端末のPOSTはAPI、ブラウザは管理画面へ)
├─ results.php            参加者向けの結果ページ(公開にした大会だけ。ログイン不要)
├─ deploy.php             GitHub の Webhook を受けて git pull(サーバーへの自動反映。Secret を設定したときだけ動く)
├─ Api/ISK-NG/index.php   ScorekeeperNG の接続先
├─ admin/                 管理画面(大会の選択/ホーム/大会設定/選手登録/的番号/入力状況/結果/端末接続/PDF/CSV)
├─ src/                   ロジック(Db, Settings, Entries, Scoring, Results, IskApi, Pdf ...)
├─ lib/tcpdf/             PDF 生成(TCPDF 同梱。LGPL)と日本語フォント(Komorebi Gothic)
├─ assets/                的面画像、アプリ用言語ファイル(ja/en)
├─ tools/                 的面定義の生成(gen_targets.php)とサンプル大会の作成(make_sample.php)。実行時は不要
├─ samples/               サンプル大会の DB 2つ(5A4D0001 屋外 / 5A4D0002 屋内。初回に data/competitions/ へコピー。パスワード sample)
├─ tests/                 run.php(全部を実行)、two_distances.php、multi.php
└─ data/competitions/     大会ごとの SQLite DB(Git 管理外)
```

## 端末がつながらない・入力画面に進まないとき

1. 管理画面の「端末接続」→ 登録された端末の一覧を見る。
   - 端末が出ない → サーバーまで届いていない(サーバーURL・大会コード、Wi-Fi、http/https を確認)。
   - 端末は出るが「的」が `-` → 的の選択(`manual`)まで進んでいない。選んだ的に選手がいるか(選手ページで的番号を割り当てたか)を確認。
2. それでも原因がわからなければ「通信ログ」をオンにして端末で同じ操作をし、ログ画面で
   `handshake → comp → manual → reconfigure` のどこで止まっているか、返事の中身を確認する。終わったらオフにしてログを消す。

端末へ返す JSON の項目の**型**(文字列か数値か)は、本家 ianseo と同じにしてあります(的面の `code` や `previous` は文字列)。
アプリは `scoring.targetface` と `targetFaces[].code` を突き合わせて入力画面の的を決めるため、型が違うと入力画面を組み立てられません。

## 制限事項・注意

- ScorekeeperNG の通信仕様は本家 ianseo のソース(`Api/ISK-NG`)から再現したものです。**実機アプリでの確認は、本番前に必ずリハーサルで行ってください**
  (接続→的選択→入力→確定→PDF の一連)。アプリの版によって挙動が異なる可能性があります。
- 「QR 読み取りの強制」「署名の正規表現」「GPS 設定」など Pro/Live 向けの端末設定には対応していません。
- WA 形式の予選(1〜2距離)・リカーブ男女・1セッションだけです。3距離以上、他の部門、マッチ戦が必要なら本家 ianseo を使ってください。
- 競技形式の変更は、得点が1本も入っていないときだけできます(得点の全消去で戻せます)。2距離対応より前に作った DB は、新規に作り直すのが確実です。
- ライセンスは本家 ianseo に準じます(このフォルダの `LICENSE.txt`)。TCPDF は LGPL(`lib/tcpdf/LICENSE.TXT`)。
