package io.github.edonkun.arcrec;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.view.Gravity;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * ArcRec の管理画面を開く WebView。
 * テザリングのアドレス(http://192.168.x.x:8080/)で開くので、ArcRec が作る端末用 QR コードもそのアドレスになる。
 * PDF・CSV はダウンロード/ArcRec に保存し、印刷・共有できるようにする。
 */
public class AdminActivity extends Activity {
    static final String EXTRA_BASE = "base";
    static final String EXTRA_PATH = "path";
    private static final int REQ_FILE = 20;

    private final ExecutorService exec = Executors.newSingleThreadExecutor();
    private WebView web;
    private ProgressBar progress;
    private LinearLayout errorBox;
    private TextView errorText;
    private Uri base;
    private ValueCallback<Uri[]> fileCallback;
    private boolean mainFrameError;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        String b = getIntent().getStringExtra(EXTRA_BASE);
        if (b == null) {
            b = NetAddresses.baseUrl("127.0.0.1", Prefs.port(this));
        }
        base = Uri.parse(b);
        if (getActionBar() != null) {
            getActionBar().setDisplayHomeAsUpEnabled(true);
        }
        setTitle("ArcRec 管理画面");

        FrameLayout root = new FrameLayout(this);
        web = new WebView(this);
        root.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);
        progress.setIndeterminate(false);
        root.addView(progress, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(this, 4), Gravity.TOP));

        errorBox = Ui.column(this);
        errorBox.setGravity(Gravity.CENTER);
        int p = Ui.dp(this, 24);
        errorBox.setPadding(p, p, p, p);
        errorBox.setBackgroundColor(Ui.attrColor(this, android.R.attr.colorBackground, 0xFFFFFFFF));
        errorBox.addView(Ui.bold(this, "サーバーにつながりません", 20));
        errorText = Ui.note(this, "");
        errorText.setGravity(Gravity.CENTER);
        errorBox.addView(errorText, Ui.matchWidth(this, 8));
        errorBox.addView(Ui.button(this, "再読み込み", v -> web.reload()), Ui.matchWidth(this, 16));
        errorBox.setVisibility(View.GONE);
        root.addView(errorBox, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        setContentView(root);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setBuiltInZoomControls(true);
        s.setDisplayZoomControls(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        CookieManager.getInstance().setAcceptCookie(true);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                if (isLocal(u)) {
                    return false;
                }
                openExternal(u);
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                mainFrameError = false;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                errorBox.setVisibility(mainFrameError ? View.VISIBLE : View.GONE);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    mainFrameError = true;
                    errorText.setText(ServerState.status() == ServerState.Status.RUNNING
                            ? "テザリングを入れ直した場合はアドレスが変わることがあります。ホーム画面に戻って開き直してください。\n("
                            + error.getDescription() + ")"
                            : "サーバーが止まっています。ホーム画面でサーバーを起動してください。");
                    errorBox.setVisibility(View.VISIBLE);
                }
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progress.setProgress(newProgress);
                progress.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
            }

            @Override
            public void onReceivedTitle(WebView view, String title) {
                if (title != null && !title.isEmpty() && !title.startsWith("http")) {
                    setTitle(title);
                }
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (fileCallback != null) {
                    fileCallback.onReceiveValue(null);
                }
                fileCallback = callback;
                Intent i = new Intent(Intent.ACTION_GET_CONTENT)
                        .addCategory(Intent.CATEGORY_OPENABLE)
                        .setType("*/*");
                try {
                    startActivityForResult(Intent.createChooser(i, "ファイルを選ぶ"), REQ_FILE);
                    return true;
                } catch (Exception e) {
                    fileCallback = null;
                    return false;
                }
            }
        });

        web.setDownloadListener((url, userAgent, contentDisposition, mimetype, contentLength) ->
                download(url, userAgent, contentDisposition, mimetype));

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState);
        } else {
            String path = getIntent().getStringExtra(EXTRA_PATH);
            web.loadUrl(base.toString() + (path == null ? "" : path));
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        web.saveState(outState);
    }

    @Override
    protected void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    @Override
    protected void onDestroy() {
        exec.shutdown();
        web.destroy();
        super.onDestroy();
    }

    @Override
    @SuppressWarnings("deprecation")
    public void onBackPressed() {
        if (web.canGoBack()) {
            web.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    @SuppressWarnings("deprecation")
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_FILE && fileCallback != null) {
            fileCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data));
            fileCallback = null;
        }
    }

    // ---- メニュー ----

    private static final int M_RELOAD = 1;
    private static final int M_ADMIN = 2;
    private static final int M_PUBLIC = 3;
    private static final int M_BROWSER = 4;

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, M_RELOAD, 0, "再読み込み");
        menu.add(0, M_ADMIN, 1, "管理者メニュー");
        menu.add(0, M_PUBLIC, 2, "公開ページ(記録会一覧)");
        menu.add(0, M_BROWSER, 3, "ブラウザで開く");
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        switch (item.getItemId()) {
            case android.R.id.home:
                finish();
                return true;
            case M_RELOAD:
                web.reload();
                return true;
            case M_ADMIN:
                web.loadUrl(base.toString() + "admin/");
                return true;
            case M_PUBLIC:
                web.loadUrl(base.toString());
                return true;
            case M_BROWSER:
                String cur = web.getUrl();
                openExternal(Uri.parse(cur != null ? cur : base.toString()));
                return true;
            default:
                return super.onOptionsItemSelected(item);
        }
    }

    // ---- リンク ----

    /** このサーバーのページか(同じポートで、テザリングのアドレスか 127.0.0.1) */
    private boolean isLocal(Uri u) {
        if (!"http".equals(u.getScheme()) || u.getPort() != base.getPort() || u.getHost() == null) {
            return false;
        }
        String h = u.getHost();
        return h.equals(base.getHost()) || h.equals("127.0.0.1") || h.equals("localhost");
    }

    private void openExternal(Uri u) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, u));
        } catch (Exception e) {
            Toast.makeText(this, "開けるアプリがありません", Toast.LENGTH_SHORT).show();
        }
    }

    // ---- ダウンロード(PDF・CSV) ----

    private void download(String url, String userAgent, String contentDisposition, String mimetype) {
        Toast.makeText(this, "保存しています…", Toast.LENGTH_SHORT).show();
        String cookie = CookieManager.getInstance().getCookie(url);
        exec.execute(() -> {
            HttpURLConnection con = null;
            try {
                con = (HttpURLConnection) new URL(url).openConnection();
                con.setConnectTimeout(10000);
                con.setReadTimeout(120000);
                if (cookie != null) {
                    con.setRequestProperty("Cookie", cookie);
                }
                if (userAgent != null) {
                    con.setRequestProperty("User-Agent", userAgent);
                }
                int code = con.getResponseCode();
                if (code != 200) {
                    throw new IOException("サーバーの応答: " + code);
                }
                String cd = con.getHeaderField("Content-Disposition");
                if (cd == null) {
                    cd = contentDisposition;
                }
                String type = con.getContentType();
                if (type == null) {
                    type = mimetype;
                }
                String mime = type == null ? "application/octet-stream"
                        : type.split(";")[0].trim().toLowerCase(Locale.ROOT);
                String name = fileName(url, cd, mime);
                final HttpURLConnection c = con;
                Uri saved = SavedFiles.saveToDownloads(this, name, mime, out -> {
                    try (InputStream in = c.getInputStream()) {
                        AppFiles.pipe(in, out);
                    }
                });
                runOnUiThread(() -> showSaved(saved, name, mime));
            } catch (IOException e) {
                runOnUiThread(() -> Toast.makeText(this, "保存できませんでした: " + e.getMessage(), Toast.LENGTH_LONG).show());
            } finally {
                if (con != null) {
                    con.disconnect();
                }
            }
        });
    }

    private static final Pattern FILENAME_STAR = Pattern.compile("filename\\*\\s*=\\s*(?:UTF-8'[^']*')?\"?([^\";]+)\"?",
            Pattern.CASE_INSENSITIVE);
    private static final Pattern FILENAME = Pattern.compile("filename\\s*=\\s*\"?([^\";]+)\"?", Pattern.CASE_INSENSITIVE);

    static String fileName(String url, String contentDisposition, String mime) {
        if (contentDisposition != null) {
            Matcher m = FILENAME_STAR.matcher(contentDisposition);
            if (m.find()) {
                try {
                    return safeName(URLDecoder.decode(m.group(1), StandardCharsets.UTF_8.name()));
                } catch (Exception ignored) {
                    // 下で普通の filename を見る
                }
            }
            m = FILENAME.matcher(contentDisposition);
            if (m.find()) {
                return safeName(m.group(1));
            }
        }
        return safeName(URLUtil.guessFileName(url, contentDisposition, mime));
    }

    private static String safeName(String s) {
        String n = s.replaceAll("[\\\\/:*?\"<>|]", "_").trim();
        return n.isEmpty() ? "arcrec" : n;
    }

    private void showSaved(Uri uri, String name, String mime) {
        if (isFinishing() || isDestroyed()) {
            return;
        }
        AlertDialog.Builder b = new AlertDialog.Builder(this)
                .setTitle(name)
                .setMessage("ダウンロード/ArcRec に保存しました。");
        if (mime.equals("application/pdf")) {
            b.setPositiveButton("印刷", (d, w) -> SavedFiles.printPdf(this, uri, name))
                    .setNeutralButton("開く", (d, w) -> SavedFiles.open(this, uri, mime))
                    .setNegativeButton("共有", (d, w) -> SavedFiles.share(this, uri, mime, name));
        } else {
            b.setPositiveButton("閉じる", null)
                    .setNeutralButton("開く", (d, w) -> SavedFiles.open(this, uri, mime))
                    .setNegativeButton("共有", (d, w) -> SavedFiles.share(this, uri, mime, name));
        }
        b.show();
    }
}
