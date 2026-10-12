package io.github.edonkun.arcrec;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.RadioButton;
import android.widget.RadioGroup;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import java.io.IOException;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** ホーム画面: サーバーの起動・停止、接続先アドレスと QR、管理画面、バックアップ */
public class MainActivity extends Activity implements ServerState.Listener {
    private static final int REQ_RESTORE = 10;
    private static final int REQ_NOTIFICATIONS = 11;
    private static final long REFRESH_MS = 3000;

    private final ExecutorService exec = Executors.newSingleThreadExecutor();
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable poll = new Runnable() {
        @Override
        public void run() {
            refreshAddresses();
            handler.postDelayed(this, REFRESH_MS);
        }
    };

    private View statusDot;
    private TextView statusText;
    private TextView statusSub;
    private Button toggleButton;
    private LinearLayout noAddressBox;
    private LinearLayout addressBox;
    private RadioGroup addressGroup;
    private TextView urlText;
    private ImageView qrImage;
    private Button batteryButton;
    private Button portButton;
    private TextView versionText;

    private String addressKey = "";
    private String shownUrl = "";
    private String currentBaseUrl;
    private boolean openAdminWhenReady;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(buildLayout());
        exec.execute(() -> {
            String v;
            try {
                AppFiles.ensureInstalled(this);
                v = AppFiles.arcrecVersion(this);
            } catch (IOException e) {
                v = null;
            }
            final String ver = v;
            runOnUiThread(() -> versionText.setText(versionLine(ver)));
        });
    }

    @Override
    protected void onResume() {
        super.onResume();
        ServerState.addListener(this);
        onServerStateChanged();
        refreshAddresses();
        refreshBatteryButton();
        handler.postDelayed(poll, REFRESH_MS);
    }

    @Override
    protected void onPause() {
        super.onPause();
        ServerState.removeListener(this);
        handler.removeCallbacks(poll);
    }

    @Override
    protected void onDestroy() {
        exec.shutdown();
        super.onDestroy();
    }

    // ---- 画面 ----

    private View buildLayout() {
        LinearLayout root = Ui.column(this);
        int pad = Ui.dp(this, 16);
        root.setPadding(pad, pad, pad, Ui.dp(this, 32));

        TextView title = Ui.bold(this, "ArcRec", 28);
        root.addView(title);
        root.addView(Ui.note(this, "アーチェリー記録会管理 — このスマホをサーバーにします"));

        // サーバーの状態
        LinearLayout status = Ui.card(this);
        LinearLayout statusRow = Ui.row(this);
        statusRow.setGravity(Gravity.CENTER_VERTICAL);
        statusDot = Ui.dot(this, 14);
        statusRow.addView(statusDot);
        statusText = Ui.bold(this, "停止中", 20);
        statusRow.addView(statusText);
        status.addView(statusRow);
        statusSub = Ui.note(this, "");
        status.addView(statusSub, Ui.matchWidth(this, 4));
        toggleButton = Ui.button(this, "サーバーを起動", v -> onToggle());
        status.addView(toggleButton, Ui.matchWidth(this, 8));
        root.addView(status, Ui.matchWidth(this, 16));

        // 接続先
        LinearLayout conn = Ui.card(this);
        conn.addView(Ui.sectionTitle(this, "接続先"));
        noAddressBox = Ui.column(this);
        noAddressBox.addView(Ui.text(this, "テザリングがオフのようです。", 16), Ui.matchWidth(this, 6));
        noAddressBox.addView(Ui.note(this,
                "Wi-Fiテザリング(アクセスポイント)をオンにすると、ここに接続先のアドレスが出ます。"), Ui.matchWidth(this, 4));
        noAddressBox.addView(Ui.button(this, "テザリングの設定を開く", v -> openTetherSettings()), Ui.matchWidth(this, 8));
        conn.addView(noAddressBox);

        addressBox = Ui.column(this);
        addressGroup = new RadioGroup(this);
        addressBox.addView(addressGroup, Ui.matchWidth(this, 4));
        urlText = Ui.bold(this, "", 22);
        urlText.setTypeface(Typeface.MONOSPACE, Typeface.BOLD);
        urlText.setTextIsSelectable(true);
        addressBox.addView(urlText, Ui.matchWidth(this, 8));

        FrameLayout qrFrame = new FrameLayout(this);
        qrImage = new ImageView(this);
        qrImage.setBackgroundColor(Color.WHITE);
        int qrSize = Ui.dp(this, 200);
        FrameLayout.LayoutParams qlp = new FrameLayout.LayoutParams(qrSize, qrSize, Gravity.CENTER_HORIZONTAL);
        qrFrame.addView(qrImage, qlp);
        addressBox.addView(qrFrame, Ui.matchWidth(this, 12));
        addressBox.addView(Ui.note(this,
                "このスマホのテザリングにつないだ端末で読み取ると、記録会一覧(公開ページ)が開きます。"
                        + "ScorekeeperNG の接続用 QR は、管理画面の「端末設定」に出ます。"), Ui.matchWidth(this, 8));
        LinearLayout urlButtons = Ui.row(this);
        Button copy = Ui.button(this, "URLをコピー", v -> copyUrl());
        Button browser = Ui.button(this, "ブラウザで開く", v -> openInBrowser());
        urlButtons.addView(copy, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        urlButtons.addView(browser, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1));
        addressBox.addView(urlButtons, Ui.matchWidth(this, 4));
        conn.addView(addressBox);
        root.addView(conn, Ui.matchWidth(this, 12));

        // 管理画面
        Button admin = Ui.primaryButton(this, "管理画面を開く", v -> openAdmin());
        root.addView(admin, Ui.matchWidth(this, 16));

        // 準備
        LinearLayout prep = Ui.card(this);
        prep.addView(Ui.sectionTitle(this, "準備・設定"));
        prep.addView(Ui.button(this, "テザリングの設定を開く", v -> openTetherSettings()), Ui.matchWidth(this, 8));
        batteryButton = Ui.button(this, "電池の最適化をオフにする", v -> requestIgnoreBattery());
        prep.addView(batteryButton, Ui.matchWidth());
        portButton = Ui.button(this, "", v -> editPort());
        prep.addView(portButton, Ui.matchWidth());
        root.addView(prep, Ui.matchWidth(this, 16));

        // データ
        LinearLayout data = Ui.card(this);
        data.addView(Ui.sectionTitle(this, "データ"));
        data.addView(Ui.button(this, "バックアップを保存", v -> backup()), Ui.matchWidth(this, 8));
        data.addView(Ui.button(this, "バックアップから復元", v -> pickRestore()), Ui.matchWidth());
        data.addView(Ui.button(this, "サーバーのログ", v -> startActivity(new Intent(this, LogActivity.class))), Ui.matchWidth());
        data.addView(Ui.note(this,
                "記録会のデータはこのアプリの中に保存されます。アプリを削除するとデータも消えるので、"
                        + "記録会の後はバックアップを保存してください(保存先: ダウンロード/ArcRec)。"), Ui.matchWidth(this, 8));
        root.addView(data, Ui.matchWidth(this, 12));

        versionText = Ui.note(this, versionLine(null));
        versionText.setGravity(Gravity.CENTER_HORIZONTAL);
        root.addView(versionText, Ui.matchWidth(this, 16));

        ScrollView scroll = new ScrollView(this);
        scroll.addView(root);
        return scroll;
    }

    private String versionLine(String arcrec) {
        String app;
        try {
            app = getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
        } catch (PackageManager.NameNotFoundException e) {
            app = "?";
        }
        return "ArcRec " + (arcrec == null ? "" : arcrec) + " / アプリ " + app;
    }

    @Override
    public void onServerStateChanged() {
        ServerState.Status s = ServerState.status();
        int port = Prefs.port(this);
        switch (s) {
            case RUNNING:
                Ui.setDotColor(statusDot, 0xFF2E7D32);
                statusText.setText("稼働中");
                statusSub.setText("ポート " + port + " で待ち受けています。画面を消しても動き続けます。");
                toggleButton.setText("サーバーを停止");
                break;
            case STARTING:
                Ui.setDotColor(statusDot, 0xFFF9A825);
                statusText.setText("起動中…");
                statusSub.setText("");
                toggleButton.setText("サーバーを停止");
                break;
            case ERROR:
                Ui.setDotColor(statusDot, 0xFFC62828);
                statusText.setText("エラー");
                statusSub.setText(ServerState.message());
                toggleButton.setText("もう一度起動");
                openAdminWhenReady = false;
                break;
            default:
                Ui.setDotColor(statusDot, Color.GRAY);
                statusText.setText("停止中");
                statusSub.setText("ScorekeeperNG や管理画面を使うには、サーバーを起動してください。");
                toggleButton.setText("サーバーを起動");
                break;
        }
        portButton.setText("ポート番号: " + port + "(変更)");
        if (s == ServerState.Status.RUNNING && openAdminWhenReady) {
            openAdminWhenReady = false;
            openAdmin();
        }
    }

    private void refreshAddresses() {
        List<NetAddresses.Addr> all = NetAddresses.list();
        StringBuilder key = new StringBuilder();
        for (NetAddresses.Addr a : all) {
            key.append(a.ip).append('/').append(a.iface).append(';');
        }
        NetAddresses.Addr sel = NetAddresses.pick(this, all);
        if (!key.toString().equals(addressKey)) {
            addressKey = key.toString();
            addressGroup.setOnCheckedChangeListener(null);
            addressGroup.removeAllViews();
            for (int i = 0; i < all.size(); i++) {
                NetAddresses.Addr a = all.get(i);
                RadioButton rb = new RadioButton(this);
                rb.setId(View.generateViewId());
                rb.setTag(a.ip);
                rb.setText(a.ip + "  (" + a.label + ")");
                addressGroup.addView(rb);
                if (sel != null && a.ip.equals(sel.ip)) {
                    rb.setChecked(true);
                }
            }
            addressGroup.setVisibility(all.size() > 1 ? View.VISIBLE : View.GONE);
            addressGroup.setOnCheckedChangeListener((g, id) -> {
                View v = g.findViewById(id);
                if (v != null) {
                    Prefs.setSelectedIp(this, (String) v.getTag());
                    refreshAddresses();
                }
            });
        }
        boolean hasTethering = false;
        for (NetAddresses.Addr a : all) {
            hasTethering |= a.isTethering();
        }
        noAddressBox.setVisibility(hasTethering ? View.GONE : View.VISIBLE);
        addressBox.setVisibility(sel == null ? View.GONE : View.VISIBLE);
        if (sel == null) {
            currentBaseUrl = null;
            shownUrl = "";
            return;
        }
        String url = NetAddresses.baseUrl(sel.ip, Prefs.port(this));
        currentBaseUrl = url;
        if (!url.equals(shownUrl)) {
            shownUrl = url;
            urlText.setText(url);
            Bitmap qr = Qr.make(url, Ui.dp(this, 200));
            qrImage.setImageBitmap(qr);
        }
    }

    private void refreshBatteryButton() {
        PowerManager pm = getSystemService(PowerManager.class);
        boolean ignoring = pm != null && pm.isIgnoringBatteryOptimizations(getPackageName());
        batteryButton.setVisibility(ignoring ? View.GONE : View.VISIBLE);
    }

    // ---- 操作 ----

    private void onToggle() {
        if (ServerState.isActive()) {
            new AlertDialog.Builder(this)
                    .setTitle("サーバーを停止しますか?")
                    .setMessage("停止すると、ScorekeeperNG からの点数入力や結果の閲覧ができなくなります。")
                    .setPositiveButton("停止", (d, w) -> ServerService.stop(this))
                    .setNegativeButton("キャンセル", null)
                    .show();
        } else {
            startServer();
        }
    }

    private void startServer() {
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQ_NOTIFICATIONS);
        }
        ServerService.start(this);
    }

    private void openAdmin() {
        if (ServerState.status() != ServerState.Status.RUNNING) {
            openAdminWhenReady = true;
            if (!ServerState.isActive()) {
                startServer();
            }
            Toast.makeText(this, "サーバーを起動しています…", Toast.LENGTH_SHORT).show();
            return;
        }
        String base = currentBaseUrl;
        if (base == null) {
            base = NetAddresses.baseUrl("127.0.0.1", Prefs.port(this));
            Toast.makeText(this, "テザリングがオフのため、端末用の QR コードは使えません", Toast.LENGTH_LONG).show();
        }
        startActivity(new Intent(this, AdminActivity.class)
                .putExtra(AdminActivity.EXTRA_BASE, base)
                .putExtra(AdminActivity.EXTRA_PATH, "admin/"));
    }

    private void copyUrl() {
        if (currentBaseUrl == null) {
            return;
        }
        ClipboardManager cm = getSystemService(ClipboardManager.class);
        cm.setPrimaryClip(ClipData.newPlainText("ArcRec", currentBaseUrl));
        if (Build.VERSION.SDK_INT < 33) {
            Toast.makeText(this, "コピーしました", Toast.LENGTH_SHORT).show();
        }
    }

    private void openInBrowser() {
        if (currentBaseUrl == null) {
            return;
        }
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(currentBaseUrl)));
        } catch (Exception e) {
            Toast.makeText(this, "ブラウザを開けません", Toast.LENGTH_SHORT).show();
        }
    }

    private void openTetherSettings() {
        Intent[] tries = {
                new Intent().setClassName("com.android.settings", "com.android.settings.TetherSettings"),
                new Intent().setClassName("com.android.settings", "com.android.settings.Settings$TetherSettingsActivity"),
                new Intent(Settings.ACTION_WIRELESS_SETTINGS),
                new Intent(Settings.ACTION_SETTINGS),
        };
        for (Intent i : tries) {
            try {
                startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
                return;
            } catch (Exception ignored) {
                // 次を試す
            }
        }
    }

    @SuppressWarnings("BatteryLife")
    private void requestIgnoreBattery() {
        try {
            startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                    Uri.parse("package:" + getPackageName())));
        } catch (Exception e) {
            try {
                startActivity(new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
            } catch (Exception ignored) {
                Toast.makeText(this, "設定画面を開けません", Toast.LENGTH_SHORT).show();
            }
        }
    }

    private void editPort() {
        EditText input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_NUMBER);
        input.setText(String.valueOf(Prefs.port(this)));
        input.setSelectAllOnFocus(true);
        FrameLayout box = new FrameLayout(this);
        int p = Ui.dp(this, 20);
        box.setPadding(p, Ui.dp(this, 8), p, 0);
        box.addView(input);
        new AlertDialog.Builder(this)
                .setTitle("ポート番号")
                .setMessage("1024〜65535。ふつうは 8080 のままで使えます。変えると、印刷済みの QR コードは使えなくなります。")
                .setView(box)
                .setPositiveButton("保存", (d, w) -> {
                    int port;
                    try {
                        port = Integer.parseInt(input.getText().toString().trim());
                    } catch (NumberFormatException e) {
                        port = -1;
                    }
                    if (!Prefs.isValidPort(port)) {
                        Toast.makeText(this, "1024〜65535 の数字を入れてください", Toast.LENGTH_LONG).show();
                        return;
                    }
                    if (port == Prefs.port(this)) {
                        return;
                    }
                    Prefs.setPort(this, port);
                    shownUrl = "";
                    refreshAddresses();
                    onServerStateChanged();
                    if (ServerState.isActive()) {
                        ServerService.restart(this);
                    }
                })
                .setNegativeButton("キャンセル", null)
                .show();
    }

    // ---- バックアップ・復元 ----

    private interface Job {
        String run() throws Exception;
    }

    /** サーバーを止めて job を動かし、終わったら(動いていたなら)起動し直す */
    private void runWithServerStopped(String progress, Job job) {
        AlertDialog dlg = new AlertDialog.Builder(this).setMessage(progress).setCancelable(false).show();
        boolean wasActive = ServerState.isActive();
        if (wasActive) {
            ServerService.stop(this);
        }
        exec.execute(() -> {
            String result;
            String error = null;
            try {
                if (wasActive && !ServerState.await(15000, ServerState.Status.STOPPED, ServerState.Status.ERROR)) {
                    throw new IOException("サーバーを止められませんでした");
                }
                result = job.run();
            } catch (Exception e) {
                result = null;
                error = e.getMessage() == null ? e.toString() : e.getMessage();
            }
            final String ok = result;
            final String err = error;
            runOnUiThread(() -> {
                if (isFinishing() || isDestroyed()) {
                    return;
                }
                dlg.dismiss();
                if (wasActive) {
                    ServerService.start(this);
                }
                new AlertDialog.Builder(this)
                        .setTitle(err == null ? "完了" : "できませんでした")
                        .setMessage(err == null ? ok : err)
                        .setPositiveButton("OK", null)
                        .show();
            });
        });
    }

    private void backup() {
        if (!DataBackup.hasCompetitions(this)) {
            Toast.makeText(this, "保存する記録会がありません", Toast.LENGTH_SHORT).show();
            return;
        }
        runWithServerStopped("バックアップを保存しています…(数秒サーバーを止めます)", () -> {
            DataBackup.backup(this, "arcrec-backup");
            return "ダウンロード/ArcRec に保存しました。\nGoogle ドライブやパソコンにも写しておくと安心です。";
        });
    }

    private void pickRestore() {
        Intent i = new Intent(Intent.ACTION_OPEN_DOCUMENT)
                .addCategory(Intent.CATEGORY_OPENABLE)
                .setType("*/*")
                .putExtra(Intent.EXTRA_MIME_TYPES,
                        new String[]{"application/zip", "application/x-zip-compressed", "application/octet-stream"});
        try {
            startActivityForResult(i, REQ_RESTORE);
        } catch (Exception e) {
            Toast.makeText(this, "ファイルを選べません", Toast.LENGTH_SHORT).show();
        }
    }

    @Override
    @SuppressWarnings("deprecation")
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQ_RESTORE || resultCode != RESULT_OK || data == null || data.getData() == null) {
            return;
        }
        Uri uri = data.getData();
        new AlertDialog.Builder(this)
                .setTitle("バックアップから復元しますか?")
                .setMessage("いまの記録会データは、選んだバックアップの内容に置き換わります。"
                        + "いまのデータは、念のため先にダウンロード/ArcRec に保存します。")
                .setPositiveButton("復元", (d, w) -> runWithServerStopped("復元しています…", () -> {
                    String saved = "";
                    if (DataBackup.hasCompetitions(this)) {
                        DataBackup.backup(this, "arcrec-before-restore");
                        saved = "\n元のデータはダウンロード/ArcRec(arcrec-before-restore-…)に保存しました。";
                    }
                    int n = DataBackup.restore(this, uri);
                    return n + " 件の記録会を復元しました。" + saved;
                }))
                .setNegativeButton("キャンセル", null)
                .show();
    }
}
