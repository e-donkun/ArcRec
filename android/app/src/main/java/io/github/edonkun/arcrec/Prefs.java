package io.github.edonkun.arcrec;

import android.content.Context;
import android.content.SharedPreferences;

/** アプリの設定(ポート番号・選んだアドレス) */
final class Prefs {
    static final int DEFAULT_PORT = 8080;

    private Prefs() {
    }

    private static SharedPreferences sp(Context c) {
        return c.getSharedPreferences("arcrec", Context.MODE_PRIVATE);
    }

    static int port(Context c) {
        int p = sp(c).getInt("port", DEFAULT_PORT);
        return isValidPort(p) ? p : DEFAULT_PORT;
    }

    static void setPort(Context c, int port) {
        sp(c).edit().putInt("port", port).apply();
    }

    static boolean isValidPort(int p) {
        return p >= 1024 && p <= 65535;
    }

    static String selectedIp(Context c) {
        return sp(c).getString("ip", null);
    }

    static void setSelectedIp(Context c, String ip) {
        sp(c).edit().putString("ip", ip).apply();
    }
}
