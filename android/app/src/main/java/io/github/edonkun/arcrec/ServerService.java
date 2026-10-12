package io.github.edonkun.arcrec;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.graphics.drawable.Icon;
import android.net.wifi.WifiManager;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.os.SystemClock;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

/**
 * PHP の組み込みサーバー(php -S 0.0.0.0:ポート)を動かすフォアグラウンドサービス。
 * 画面を消しても止まらないよう、通知を出してスリープ中も CPU を動かす。PHP が落ちたら起動し直す。
 */
public class ServerService extends Service {
    static final String ACTION_START = "io.github.edonkun.arcrec.START";
    static final String ACTION_STOP = "io.github.edonkun.arcrec.STOP";
    static final String ACTION_RESTART = "io.github.edonkun.arcrec.RESTART";

    private static final String CHANNEL = "server";
    private static final int NOTIFICATION_ID = 1;
    private static final int WORKERS = 4;
    private static final long LOG_FILE_MAX = 1024 * 1024;

    private final ExecutorService exec = Executors.newSingleThreadExecutor();
    private final Handler main = new Handler(Looper.getMainLooper());
    private volatile Process process;
    private volatile boolean stopping;
    private volatile long startedAt;
    private int quickFailures;
    private PowerManager.WakeLock wakeLock;
    private WifiManager.WifiLock wifiLock;

    static void start(Context c) {
        c.startForegroundService(new Intent(c, ServerService.class).setAction(ACTION_START));
    }

    static void restart(Context c) {
        c.startForegroundService(new Intent(c, ServerService.class).setAction(ACTION_RESTART));
    }

    static void stop(Context c) {
        if (!ServerState.isActive()) {
            return;
        }
        c.startService(new Intent(c, ServerService.class).setAction(ACTION_STOP));
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent == null || intent.getAction() == null ? ACTION_START : intent.getAction();
        if (ACTION_STOP.equals(action)) {
            exec.execute(this::shutdown);
            return START_NOT_STICKY;
        }
        try {
            goForeground("起動しています…");
        } catch (RuntimeException e) {
            // バックグラウンドからは起動できない(Android 12 以降の制限)。アプリを開いてから起動し直す
            ServerState.set(ServerState.Status.ERROR, "サーバーを起動できませんでした。アプリを開いて起動してください。", 0);
            stopSelf();
            return START_NOT_STICKY;
        }
        acquireLocks();
        if (ACTION_RESTART.equals(action)) {
            exec.execute(() -> {
                stopProcess();
                quickFailures = 0;
                launch();
            });
        } else {
            exec.execute(() -> {
                if (process == null || !process.isAlive()) {
                    quickFailures = 0;
                    launch();
                }
            });
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        stopping = true;
        if (process != null) {
            stopProcess();
        }
        releaseLocks();
        exec.shutdownNow();
        if (ServerState.status() != ServerState.Status.ERROR) {
            ServerState.set(ServerState.Status.STOPPED, "", 0);
        }
        super.onDestroy();
    }

    // ---- PHP の起動と停止(exec のスレッドで動かす) ----

    private void launch() {
        stopping = false;
        int port = Prefs.port(this);
        ServerState.set(ServerState.Status.STARTING, "", port);
        try {
            AppFiles.ensureInstalled(this);
            AppFiles.ensurePhpRuntime(this);
        } catch (IOException e) {
            fail("ArcRec 本体を準備できませんでした: " + e.getMessage());
            return;
        }
        File php = AppFiles.php(this);
        if (!php.isFile()) {
            fail("PHP が見つかりません(" + php + ")。このアプリは 64 ビット(arm64)の端末で動きます。");
            return;
        }
        killStray();

        File www = AppFiles.webRoot(this);
        File tmp = AppFiles.tmpDir(this);
        List<String> cmd = new ArrayList<>();
        cmd.add(php.getAbsolutePath());
        cmd.add("-n");   // php.ini を読まない(設定は下の -d だけ)
        addIni(cmd, "output_buffering", "4096");
        addIni(cmd, "memory_limit", "256M");
        addIni(cmd, "max_execution_time", "120");
        addIni(cmd, "post_max_size", "32M");
        addIni(cmd, "upload_max_filesize", "16M");
        addIni(cmd, "display_errors", "0");
        addIni(cmd, "log_errors", "1");
        addIni(cmd, "date.timezone", "Asia/Tokyo");
        addIni(cmd, "default_charset", "UTF-8");
        addIni(cmd, "sys_temp_dir", tmp.getAbsolutePath());
        addIni(cmd, "upload_tmp_dir", tmp.getAbsolutePath());
        addIni(cmd, "session.save_path", tmp.getAbsolutePath());
        addIni(cmd, "opcache.lockfile_path", tmp.getAbsolutePath());
        cmd.add("-S");
        cmd.add("0.0.0.0:" + port);
        cmd.add("-t");
        cmd.add(www.getAbsolutePath());
        cmd.add(AppFiles.router(this).getAbsolutePath());

        ProcessBuilder pb = new ProcessBuilder(cmd);
        pb.directory(www);
        pb.redirectErrorStream(true);
        Map<String, String> env = pb.environment();
        env.put("LITE_DATA_DIR", AppFiles.dataDir(this).getAbsolutePath());
        env.put("TMPDIR", tmp.getAbsolutePath());
        env.put("HOME", getFilesDir().getAbsolutePath());
        env.put("LD_LIBRARY_PATH", AppFiles.phpLibDir(this).getAbsolutePath());
        env.put("PHP_CLI_SERVER_WORKERS", String.valueOf(WORKERS));

        log("---- サーバーを起動します(ポート " + port + "、Android " + Build.VERSION.RELEASE
                + "、" + Build.MANUFACTURER + " " + Build.MODEL + ") ----");
        final Process p;
        try {
            p = pb.start();
        } catch (IOException e) {
            fail("PHP を起動できませんでした: " + e.getMessage());
            return;
        }
        process = p;
        startedAt = SystemClock.elapsedRealtime();
        Thread reader = new Thread(() -> readOutput(p), "php-output");
        reader.setDaemon(true);
        reader.start();

        if (waitForPort(p, port, 15000)) {
            ServerState.set(ServerState.Status.RUNNING, "", port);
            main.post(() -> goForeground(null));
        } else if (process == p && !stopping) {
            // 待ち受けできないまま(すぐ終了したときは readOutput 側で扱う)
            if (p.isAlive()) {
                stopProcess();
                fail("サーバーが応答しません。ログを確認してください。");
            }
        }
    }

    private static void addIni(List<String> cmd, String key, String value) {
        cmd.add("-d");
        cmd.add(key + "=" + value);
    }

    private static boolean waitForPort(Process p, int port, long timeoutMs) {
        long end = SystemClock.elapsedRealtime() + timeoutMs;
        while (SystemClock.elapsedRealtime() < end) {
            if (!p.isAlive()) {
                return false;
            }
            try (Socket s = new Socket()) {
                s.connect(new InetSocketAddress("127.0.0.1", port), 300);
                return true;
            } catch (IOException ignored) {
                SystemClock.sleep(200);
            }
        }
        return false;
    }

    /** PHP の出力をログに書く。終わったら(PHP が終了したら)後始末をする。 */
    private void readOutput(Process p) {
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream(), StandardCharsets.UTF_8))) {
            String line;
            while ((line = r.readLine()) != null) {
                log(line);
            }
        } catch (IOException ignored) {
            // 終了時に閉じられる
        }
        int code;
        try {
            code = p.waitFor();
        } catch (InterruptedException e) {
            return;
        }
        final int exitCode = code;
        try {
            exec.execute(() -> onExited(p, exitCode));
        } catch (Exception ignored) {
            // サービス終了後
        }
    }

    private void onExited(Process p, int code) {
        if (stopping || process != p) {
            return;   // 自分で止めた
        }
        process = null;
        log("---- PHP が終了しました(終了コード " + code + ") ----");
        long uptime = SystemClock.elapsedRealtime() - startedAt;
        quickFailures = uptime < 15000 ? quickFailures + 1 : 0;
        if (quickFailures >= 3) {
            String last = ServerState.lastLines(3);
            fail("サーバーを起動できませんでした" + (last.isEmpty() ? "" : "\n" + last));
            return;
        }
        // 予期せず終了したので起動し直す
        SystemClock.sleep(1500);
        if (!stopping) {
            launch();
        }
    }

    private void fail(String message) {
        log(message);
        stopping = true;
        stopProcess();
        ServerState.set(ServerState.Status.ERROR, message, 0);
        main.post(this::leaveForeground);
    }

    private void shutdown() {
        stopping = true;
        stopProcess();
        log("---- サーバーを停止しました ----");
        // STOPPED にするのは onDestroy(サービスが本当に終わってから)
        main.post(this::leaveForeground);
    }

    private void stopProcess() {
        Process p = process;
        process = null;
        if (p != null) {
            p.destroy();
            try {
                if (!p.waitFor(3, TimeUnit.SECONDS)) {
                    p.destroyForcibly();
                }
            } catch (InterruptedException ignored) {
                p.destroyForcibly();
            }
        }
        killStray();
    }

    /** 前回の PHP(ワーカーを含む)が残っていれば終わらせる。同じアプリのプロセスしか対象にならない。 */
    private void killStray() {
        try {
            Process k = new ProcessBuilder("/system/bin/pkill", "-f", "libphp\\.so").start();
            k.waitFor(3, TimeUnit.SECONDS);
            SystemClock.sleep(300);
        } catch (Exception ignored) {
            // pkill が無い端末では何もしない
        }
    }

    // ---- 通知・スリープ対策(画面のスレッド) ----

    private void goForeground(String text) {
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (nm.getNotificationChannel(CHANNEL) == null) {
            NotificationChannel ch = new NotificationChannel(CHANNEL, "サーバーの状態", NotificationManager.IMPORTANCE_LOW);
            ch.setShowBadge(false);
            nm.createNotificationChannel(ch);
        }
        Notification n = buildNotification(text);
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(NOTIFICATION_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        } else {
            startForeground(NOTIFICATION_ID, n);
        }
    }

    private Notification buildNotification(String text) {
        if (text == null) {
            List<NetAddresses.Addr> all = NetAddresses.list();
            NetAddresses.Addr a = NetAddresses.pick(this, all);
            int port = Prefs.port(this);
            text = a == null ? "ポート " + port + " で待ち受け中(テザリングをオンにしてください)"
                    : NetAddresses.baseUrl(a.ip, port);
        }
        PendingIntent open = PendingIntent.getActivity(this, 0,
                new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
                PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        PendingIntent stop = PendingIntent.getService(this, 1,
                new Intent(this, ServerService.class).setAction(ACTION_STOP),
                PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        Notification.Builder b = new Notification.Builder(this, CHANNEL)
                .setSmallIcon(R.drawable.ic_stat_server)
                .setContentTitle("ArcRec サーバー稼働中")
                .setContentText(text)
                .setOngoing(true)
                .setShowWhen(false)
                .setContentIntent(open)
                .addAction(new Notification.Action.Builder(
                        Icon.createWithResource(this, R.drawable.ic_stop), "停止", stop).build());
        if (Build.VERSION.SDK_INT >= 31) {
            b.setForegroundServiceBehavior(Notification.FOREGROUND_SERVICE_IMMEDIATE);
        }
        return b.build();
    }

    private void leaveForeground() {
        releaseLocks();
        stopForeground(STOP_FOREGROUND_REMOVE);
        stopSelf();
    }

    @SuppressWarnings("deprecation")
    private void acquireLocks() {
        if (wakeLock == null) {
            PowerManager pm = getSystemService(PowerManager.class);
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "ArcRec:server");
            wakeLock.setReferenceCounted(false);
        }
        if (!wakeLock.isHeld()) {
            wakeLock.acquire();
        }
        if (wifiLock == null) {
            WifiManager wm = getApplicationContext().getSystemService(WifiManager.class);
            if (wm != null) {
                wifiLock = wm.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, "ArcRec:server");
                wifiLock.setReferenceCounted(false);
            }
        }
        if (wifiLock != null && !wifiLock.isHeld()) {
            wifiLock.acquire();
        }
    }

    private void releaseLocks() {
        if (wakeLock != null && wakeLock.isHeld()) {
            wakeLock.release();
        }
        if (wifiLock != null && wifiLock.isHeld()) {
            wifiLock.release();
        }
    }

    // ---- ログ ----

    private final Object logLock = new Object();

    private void log(String line) {
        ServerState.log(line);
        synchronized (logLock) {
            try {
                File dir = AppFiles.logDir(this);
                AppFiles.mkdirs(dir);
                File f = new File(dir, "server.log");
                if (f.length() > LOG_FILE_MAX) {
                    File old = new File(dir, "server.log.1");
                    //noinspection ResultOfMethodCallIgnored
                    old.delete();
                    //noinspection ResultOfMethodCallIgnored
                    f.renameTo(old);
                }
                try (OutputStream os = new FileOutputStream(f, true)) {
                    os.write((line + "\n").getBytes(StandardCharsets.UTF_8));
                }
            } catch (IOException ignored) {
                // ログが書けなくても止めない
            }
        }
    }
}
