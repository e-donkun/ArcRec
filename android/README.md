# ArcRec Android アプリ

Android スマホ 1 台を ArcRec のサーバーにするアプリです。スマホの Wi-Fi テザリングに ScorekeeperNG のタブレットや管理用の端末をつなぎ、インターネットやパソコンなしで記録会を運営できます。

```
  ┌───────────────── サーバー用スマホ(このアプリ) ─────────────────┐
  │  Wi-Fi テザリング(例 192.168.43.1)                              │
  │  ArcRec(PHP 組み込みサーバー :8080)  ← 管理画面もこのスマホで開ける │
  └───────────────────────────────────────────────────────────────┘
        ▲ Wi-Fi             ▲ Wi-Fi                ▲ Wi-Fi
   ScorekeeperNG         ScorekeeperNG         観戦・結果確認のスマホ
   (的 1)                (的 2)                (http://192.168.43.1:8080/)
```

## アプリでできること

- ボタン 1 つで ArcRec のサーバーを起動・停止する(画面を消しても動き続ける。通知から停止もできる)
- テザリングの接続先 URL を表示し、コピーする
- 管理画面をアプリの中で開く。テザリングのアドレスで開くので、「端末設定」の ScorekeeperNG 用 QR コードはそのまま読み取れる
- スコアカード・結果の PDF と CSV を「ダウンロード/ArcRec」に保存し、印刷・共有する
- 記録会データのバックアップ(ZIP)と復元
- サーバーのログを見る・共有する
- APK に含まれるオープンソースライセンスをアプリ内で確認する

ArcRec 本体の機能(記録会の作成、参加者、的番号、点数管理、結果)は、パソコンで動かす場合と同じです。

## 動作環境

- Android 10 以上、64 ビット(arm64)のスマホ(ここ数年の機種はほぼすべて)
- Wi-Fi テザリングが使えること(SIM が入っていなくても使える機種が多いが、使えない機種もあるので事前に確認する)
- ScorekeeperNG を動かすタブレット・スマホ

## インストール

1. [リリース一覧](https://github.com/e-donkun/arcrec/releases)から `ArcRec-バージョン.apk` をスマホでダウンロードする。
2. ダウンロードした APK を開き、「この提供元のアプリを許可」を求められたら許可してインストールする。
3. 新しい版は、同じ手順で上書きインストールする(記録会データは残る)。

## はじめに 1 回だけ行う設定

1. アプリを開き、「電池の最適化をオフにする」を押して許可する(スリープ中にサーバーが止められないようにする)。
2. 「テザリングの設定を開く」から Wi-Fi テザリング(アクセスポイント)を設定する。
   - ネットワーク名とパスワードを決める(パスワードは必ず設定する)。
   - 「接続している端末がないときに自動でオフ」などの設定があれば、オフにする。
   - 周波数帯が選べる場合は 2.4 GHz にすると、古いタブレットでもつながりやすい。
3. テザリングをオンにして「サーバーを起動」→「管理画面を開く」。全体管理者パスワードを設定する。
4. 記録会を作り、ScorekeeperNG のタブレットを 1 台つないで、点数が届くことを確かめる(リハーサル)。

## 記録会当日の運営手順

**準備**

1. サーバー用スマホを充電しておく。当日は充電しながら(モバイルバッテリーなど)使う。
2. サーバー用スマホで Wi-Fi テザリングをオンにする。
3. アプリで「サーバーを起動」する。「接続先」に `http://192.168.x.x:8080/` のようなアドレスが出ることを確かめる。
4. ScorekeeperNG のタブレットを、すべてこのテザリングの Wi-Fi につなぐ。

**端末の接続**

5. 「管理画面を開く」→ 記録会を開く →「端末設定」の QR コードを各タブレットの ScorekeeperNG で読み取る。
6. QR 付きのスコアカードや的 QR 一覧を印刷する場合は、**テザリングをオンにした後に** PDF を作る(下の「注意」参照)。PDF は保存後に「印刷」で Android の印刷画面に送れる。

**競技中**

7. 管理画面の「点数管理」で入力状況を確認する。訂正は管理画面から行える。
8. テザリングとサーバーは競技が終わるまでオンのままにする。

**終了後**

9. 「点数管理」で「全員を確定」し、「結果」で結果 PDF / CSV を保存する。必要なら結果を公開にする(テザリングにつないだスマホで `http://アドレス:8080/` を開くと見られる)。
10. アプリのホームで「バックアップを保存」する。保存した ZIP(ダウンロード/ArcRec)は Google ドライブやパソコンにも写しておく。
11. 「サーバーを停止」し、テザリングをオフにする。

## 注意

- **テザリングのアドレスは変わることがある。** Android 11 以降の多くの機種では、テザリングをオンにするたびにアドレス(192.168.x.x)が変わる。ScorekeeperNG の接続や QR コードはアドレスを含むため、テザリングをオンにした後に QR を読み取り・印刷し、競技中はテザリングを切らない。アドレスが変わったら、管理画面を開き直して「端末設定」の QR を読み取り直す。
- テザリングにつなげる台数は機種によって上限がある(10 台前後が多い)。的の数が多いときは事前に確認する。
- 通信は暗号化されない HTTP。テザリングには必ずパスワードを設定し、知らない人をつながせない。
- 記録会データはアプリの中に保存される。**アプリを削除するとデータも消える。** 機種変更や再インストールの前にバックアップを保存し、新しい端末で「バックアップから復元」する。
- 管理画面は、テザリングにつないだほかのスマホやパソコンのブラウザからも開ける(`http://アドレス:8080/admin/`)。
- ポート番号は既定の 8080 のまま使う。変えた場合は印刷済みの QR が使えなくなる。

## 困ったとき

| 症状 | 確かめること |
|---|---|
| タブレットからつながらない | タブレットがテザリングの Wi-Fi につながっているか(モバイル回線や別の Wi-Fi になっていないか)。アプリが「稼働中」か。QR を読み直す。 |
| 「接続先」にアドレスが出ない | テザリングがオンか。オンにして数秒待つ。 |
| 画面を消すと止まる | 「電池の最適化をオフにする」。機種独自の省電力設定(アプリのスリープなど)でも ArcRec を対象外にする。 |
| サーバーが起動しない(エラー) | 「サーバーのログ」を開き、「共有」で内容を送って問い合わせる。 |

## 開発者向け

### 仕組み

- ArcRec 本体(PHP)を APK に `assets/arcrec.zip` として入れ、初回起動時とアプリ更新後に `files/www/` に展開する。記録会データは `files/arcrec-data/`(環境変数 `LITE_DATA_DIR`)に置き、更新しても残す。
- APK には Termux の Android arm64 PHP を `lib/arm64-v8a/libphpandroid.so` として同梱する。Android 10 以降はアプリのデータ領域から実行できないため、インストール先(`nativeLibraryDir`)から起動し、PHP の共有ライブラリは `files/php-runtime/lib/` に展開する。
- `ServerService`(フォアグラウンドサービス)が `libphpandroid.so -n -d … -S 0.0.0.0:8080 -t files/www files/router.php` を起動する。ワーカーは 4(`PHP_CLI_SERVER_WORKERS`)。PHP が落ちたら起動し直す。
- `router.php` は Apache の `.htaccess` の代わりに、`src/` `lib/` `data/` `config.php` などを 404 にする。
- 管理画面はテザリングのアドレス(`http://192.168.x.x:8080/admin/`)で WebView に開く。ArcRec は開いている URL から端末用 QR のアドレスを作るため。

| ファイル | 内容 |
|---|---|
| `app/build.gradle.kts` | 版(`LITE_VERSION` から)、ArcRec 本体の同梱、署名 |
| `app/src/main/assets/router.php` | 組み込みサーバーのルーター |
| `app/src/main/java/…/ServerService.java` | PHP の起動・停止・再起動、通知、スリープ対策、ログ |
| `MainActivity.java` | ホーム画面(状態、接続先、バックアップ) |
| `AdminActivity.java` | 管理画面の WebView(PDF・CSV の保存、印刷、ファイル選択) |
| `LicenseActivity.java` | APK に含めたライセンス文書の表示 |
| `AppFiles.java` / `DataBackup.java` | 展開・データの置き場所 / バックアップと復元 |
| `NetAddresses.java` | テザリングのアドレスを探す |
| `scripts/fetch-php.sh` | Linux arm64 PHP を取得し、QEMU を使ったテストに使う |
| `scripts/fetch-php-android.sh` | 固定した Termux revision から Readline 無効版の Android arm64 PHP をビルドし、必要なライブラリ・ライセンス・一覧を取得する |
| `scripts/check-php.sh` | Linux arm64 PHP で拡張・ArcRec のテスト・組み込みサーバーを確認する(QEMU で x86_64 でも動く) |

### ビルド

GitHub Actions の「Android APK」(`.github/workflows/android-apk.yml`)が、リリースを公開したときに APK と `Android-PHP-sources-<commit>.tsv`(対応ソース・依存 manifest)をリリースに添付する。手動実行(Run workflow)でも Actions の成果物から両方取得できる。

手元でビルドする場合(JDK 17 と Android SDK が必要。Android Studio でこのフォルダを開いてもよい):

```sh
bash android/scripts/fetch-php.sh       # PHP を取得(最初に 1 回)
bash android/scripts/check-php.sh       # 任意: qemu-user があれば PHP を確認
bash android/scripts/fetch-php-android.sh # 固定ソースからAndroid向けPHPをビルド
cd android && ./gradlew assembleRelease
# → android/app/build/outputs/apk/release/app-release.apk
```

APK の版(versionName)は ArcRec の `LITE_VERSION`、versionCode はそこから計算する(0.17.0 → 17000)。`-ParcrecVersionCode=…` で上書きできる。

### APK の署名鍵

上書き更新(データを残したままの更新)には、いつも同じ鍵で署名する必要がある。鍵を Secrets に登録するまでは、Actions はその場で作るデバッグ鍵で署名する(試用のみ。次の版に上書きできない)。

```sh
keytool -genkeypair -v -keystore arcrec-release.jks -alias arcrec \
  -keyalg RSA -keysize 4096 -validity 36500
base64 -w0 arcrec-release.jks > arcrec-release.jks.b64
```

リポジトリの Settings → Secrets and variables → Actions に登録する。

| Secret | 値 |
|---|---|
| `ARCREC_KEYSTORE_BASE64` | `arcrec-release.jks.b64` の中身 |
| `ARCREC_KEYSTORE_PASSWORD` | キーストアのパスワード |
| `ARCREC_KEY_ALIAS` | `arcrec` |
| `ARCREC_KEY_PASSWORD` | 鍵のパスワード(キーストアと同じなら省略可) |

`arcrec-release.jks` とパスワードは安全な場所に保管する(コミットしない)。失うと、利用者はアプリを削除して入れ直す(データが消える)ことになる。

### PHP の版を変える

Linux 上のテスト用 PHP は従来どおり PHP 8.3 系を取得する。Android APK 用 PHP は `android/patches/termux-php-without-readline.patch` を固定した Termux revision に適用してビルドし、Readline 依存が ELF にないことを検証する。依存パッケージの版・SHA-256 とソース revision を記録した `PACKAGE-MANIFEST.tsv`、PHP License、依存パッケージの著作権・ライセンス文書を APK 内に同梱し、ホームの「オープンソースライセンス」から閲覧できる。

APK に含まれる `PACKAGE-MANIFEST.tsv` には ArcRec のソース revision、Termux のソース revision、各ランタイム依存の版・ライセンス・ビルド recipe・上流ソース URL・SHA-256 を記録する。配布した APK と同じ ArcRec revision のソース一式、および manifest に記録された Termux recipe と PHP/依存ソースを対応ソースとして提供する。
