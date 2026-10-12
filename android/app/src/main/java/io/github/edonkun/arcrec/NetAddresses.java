package io.github.edonkun.arcrec;

import android.content.Context;

import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

/**
 * この端末の IPv4 アドレスのうち、ほかの端末からつながりそうなもの(テザリング・Wi-Fi など)。
 * Wi-Fi テザリングのアドレスを先頭にする。モバイル回線や VPN のアドレスは出さない。
 */
final class NetAddresses {
    static final class Addr {
        final String ip;
        final String iface;
        final String label;
        final int rank;

        Addr(String ip, String iface, String label, int rank) {
            this.ip = ip;
            this.iface = iface;
            this.label = label;
            this.rank = rank;
        }

        boolean isTethering() {
            return rank <= 2;
        }
    }

    private static final String[] SKIP = {
            "rmnet", "ccmni", "v4-", "clat", "dummy", "tun", "ppp", "seth", "sit", "ip6", "epdg", "rev_rmnet", "p2p",
    };

    private NetAddresses() {
    }

    static List<Addr> list() {
        List<Addr> out = new ArrayList<>();
        try {
            for (NetworkInterface ni : Collections.list(NetworkInterface.getNetworkInterfaces())) {
                if (!ni.isUp() || ni.isLoopback()) {
                    continue;
                }
                String name = ni.getName() == null ? "" : ni.getName().toLowerCase(Locale.ROOT);
                if (skip(name)) {
                    continue;
                }
                for (InetAddress a : Collections.list(ni.getInetAddresses())) {
                    if (!(a instanceof Inet4Address) || a.isLoopbackAddress() || a.isLinkLocalAddress()) {
                        continue;
                    }
                    out.add(classify(a.getHostAddress(), name));
                }
            }
        } catch (Exception ignored) {
            // 一覧を取れないときは空にする
        }
        out.sort((x, y) -> x.rank != y.rank ? Integer.compare(x.rank, y.rank) : x.iface.compareTo(y.iface));
        return out;
    }

    private static boolean skip(String name) {
        for (String s : SKIP) {
            if (name.startsWith(s)) {
                return true;
            }
        }
        return false;
    }

    static Addr classify(String ip, String name) {
        if (name.startsWith("ap") || name.startsWith("swlan") || name.startsWith("softap")) {
            return new Addr(ip, name, "Wi-Fiテザリング", 0);
        }
        if (name.startsWith("wlan") && (ip.endsWith(".1") || ip.equals("192.168.43.1"))) {
            return new Addr(ip, name, "Wi-Fiテザリング", 0);
        }
        if (name.startsWith("rndis") || name.startsWith("usb") || name.startsWith("ncm")) {
            return new Addr(ip, name, "USBテザリング", 1);
        }
        if (name.startsWith("bt")) {
            return new Addr(ip, name, "Bluetoothテザリング", 2);
        }
        if (name.startsWith("wlan") || name.startsWith("eth")) {
            return new Addr(ip, name, "Wi-Fi・LAN(ルーターに接続)", 3);
        }
        return new Addr(ip, name, "その他", 5);
    }

    /** 使うアドレス: 選んだものがあればそれ、無ければ先頭。どれも無ければ null */
    static Addr pick(Context c, List<Addr> all) {
        if (all.isEmpty()) {
            return null;
        }
        String sel = Prefs.selectedIp(c);
        if (sel != null) {
            for (Addr a : all) {
                if (a.ip.equals(sel)) {
                    return a;
                }
            }
        }
        return all.get(0);
    }

    static String baseUrl(String ip, int port) {
        return "http://" + ip + ":" + port + "/";
    }
}
