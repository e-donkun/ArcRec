package io.github.edonkun.arcrec;

import android.app.Activity;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Intent;
import android.graphics.Typeface;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

/** サーバー(PHP)のログ。問い合わせのときに共有できる。 */
public class LogActivity extends Activity {
    private final Handler handler = new Handler(Looper.getMainLooper());
    private ScrollView scroll;
    private TextView text;
    private long shownVersion = -1;

    private final Runnable refresh = new Runnable() {
        @Override
        public void run() {
            long v = ServerState.logVersion();
            if (v != shownVersion) {
                shownVersion = v;
                boolean atBottom = !scroll.canScrollVertically(1);
                String log = ServerState.logText();
                text.setText(log.isEmpty() ? "(まだログはありません)" : log);
                if (atBottom) {
                    scroll.post(() -> scroll.fullScroll(View.FOCUS_DOWN));
                }
            }
            handler.postDelayed(this, 1000);
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (getActionBar() != null) {
            getActionBar().setDisplayHomeAsUpEnabled(true);
        }
        text = Ui.text(this, "", 11);
        text.setTypeface(Typeface.MONOSPACE);
        text.setTextIsSelectable(true);
        int p = Ui.dp(this, 12);
        text.setPadding(p, p, p, p);
        scroll = new ScrollView(this);
        scroll.addView(text);
        setContentView(scroll);
    }

    @Override
    protected void onResume() {
        super.onResume();
        handler.post(refresh);
    }

    @Override
    protected void onPause() {
        super.onPause();
        handler.removeCallbacks(refresh);
    }

    private static final int M_SHARE = 1;
    private static final int M_COPY = 2;

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, M_SHARE, 0, "共有");
        menu.add(0, M_COPY, 1, "コピー");
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        String log = header() + ServerState.logText();
        switch (item.getItemId()) {
            case android.R.id.home:
                finish();
                return true;
            case M_SHARE:
                startActivity(Intent.createChooser(new Intent(Intent.ACTION_SEND).setType("text/plain")
                        .putExtra(Intent.EXTRA_SUBJECT, "ArcRec サーバーのログ")
                        .putExtra(Intent.EXTRA_TEXT, log), "ログを共有"));
                return true;
            case M_COPY:
                getSystemService(ClipboardManager.class).setPrimaryClip(ClipData.newPlainText("ArcRec log", log));
                if (Build.VERSION.SDK_INT < 33) {
                    Toast.makeText(this, "コピーしました", Toast.LENGTH_SHORT).show();
                }
                return true;
            default:
                return super.onOptionsItemSelected(item);
        }
    }

    private String header() {
        return "ArcRec " + AppFiles.arcrecVersion(this) + " / Android " + Build.VERSION.RELEASE
                + " / " + Build.MANUFACTURER + " " + Build.MODEL + "\n\n";
    }
}
