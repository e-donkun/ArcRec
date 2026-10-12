package io.github.edonkun.arcrec;

import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;

import java.util.ArrayDeque;
import java.util.concurrent.CopyOnWriteArrayList;

/** サーバーの状態とログ(アプリの中で共有する)。変化は画面のスレッドで Listener に知らせる。 */
final class ServerState {
    enum Status { STOPPED, STARTING, RUNNING, ERROR }

    interface Listener {
        void onServerStateChanged();
    }

    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final CopyOnWriteArrayList<Listener> LISTENERS = new CopyOnWriteArrayList<>();
    private static final int MAX_LOG_LINES = 500;
    private static final ArrayDeque<String> LOG = new ArrayDeque<>();

    private static volatile Status status = Status.STOPPED;
    private static volatile String message = "";
    private static volatile int port;
    private static volatile long logVersion;

    private ServerState() {
    }

    static Status status() {
        return status;
    }

    static String message() {
        return message;
    }

    /** 動いているポート(動いていなければ 0) */
    static int port() {
        return status == Status.RUNNING ? port : 0;
    }

    static boolean isActive() {
        return status == Status.RUNNING || status == Status.STARTING;
    }

    static void set(Status s, String msg, int p) {
        status = s;
        message = msg == null ? "" : msg;
        port = p;
        MAIN.post(() -> {
            for (Listener l : LISTENERS) {
                l.onServerStateChanged();
            }
        });
    }

    static void addListener(Listener l) {
        LISTENERS.addIfAbsent(l);
    }

    static void removeListener(Listener l) {
        LISTENERS.remove(l);
    }

    /** 状態が want のどれかになるまで待つ(画面以外のスレッドから)。なったら true */
    static boolean await(long timeoutMs, Status... want) {
        long end = SystemClock.elapsedRealtime() + timeoutMs;
        while (SystemClock.elapsedRealtime() < end) {
            for (Status w : want) {
                if (status == w) {
                    return true;
                }
            }
            SystemClock.sleep(100);
        }
        return false;
    }

    static void log(String line) {
        synchronized (LOG) {
            LOG.addLast(line);
            while (LOG.size() > MAX_LOG_LINES) {
                LOG.removeFirst();
            }
            logVersion++;
        }
    }

    static long logVersion() {
        return logVersion;
    }

    static String logText() {
        synchronized (LOG) {
            StringBuilder sb = new StringBuilder();
            for (String s : LOG) {
                sb.append(s).append('\n');
            }
            return sb.toString();
        }
    }

    /** 最後の n 行(エラーの説明用) */
    static String lastLines(int n) {
        synchronized (LOG) {
            String[] all = LOG.toArray(new String[0]);
            StringBuilder sb = new StringBuilder();
            for (int i = Math.max(0, all.length - n); i < all.length; i++) {
                sb.append(all[i]).append('\n');
            }
            return sb.toString().trim();
        }
    }
}
