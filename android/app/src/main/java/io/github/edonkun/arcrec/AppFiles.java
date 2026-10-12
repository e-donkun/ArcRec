package io.github.edonkun.arcrec;

import android.content.Context;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.zip.ZipEntry;
import java.util.zip.ZipInputStream;

/**
 * アプリ内のファイルの置き場所と、ArcRec 本体(assets/arcrec.zip)の展開。
 *
 * <pre>
 * files/www/          ArcRec 本体(アプリの更新ごとに入れ替える)
 * files/router.php    組み込みサーバーのルーター
 * files/arcrec-data/  記録会データ(LITE_DATA_DIR。更新しても残る)
 * files/tmp/          PHP の一時ファイル
 * files/logs/         サーバーのログ
 * </pre>
 */
final class AppFiles {
    private AppFiles() {
    }

    static File webRoot(Context c) {
        return new File(c.getFilesDir(), "www");
    }

    static File router(Context c) {
        return new File(c.getFilesDir(), "router.php");
    }

    static File dataDir(Context c) {
        return new File(c.getFilesDir(), "arcrec-data");
    }

    static File tmpDir(Context c) {
        return new File(c.getFilesDir(), "tmp");
    }

    static File logDir(Context c) {
        return new File(c.getFilesDir(), "logs");
    }

    /** 静的ビルドの PHP。インストール時に展開されたネイティブライブラリの場所から実行する。 */
    static File php(Context c) {
        return new File(c.getApplicationInfo().nativeLibraryDir, "libphp.so");
    }

    /** ArcRec 本体を展開する。展開済みで、アプリが更新されていなければ何もしない。 */
    static synchronized void ensureInstalled(Context c) throws IOException {
        String stamp = installStamp(c);
        File www = webRoot(c);
        File stampFile = new File(c.getFilesDir(), "www.stamp");
        if (www.isDirectory() && new File(www, "index.php").isFile() && stampFile.isFile()
                && stamp.equals(readText(stampFile).trim())) {
            return;
        }
        File fresh = new File(c.getFilesDir(), "www.new");
        deleteRecursively(fresh);
        try (InputStream in = c.getAssets().open("arcrec.zip")) {
            unzip(in, fresh);
        }
        if (!new File(fresh, "index.php").isFile()) {
            throw new IOException("ArcRec 本体の展開に失敗しました");
        }
        try (InputStream in = c.getAssets().open("router.php")) {
            copy(in, router(c));
        }
        deleteRecursively(www);
        if (!fresh.renameTo(www)) {
            throw new IOException("ArcRec 本体の配置に失敗しました");
        }
        mkdirs(dataDir(c));
        mkdirs(tmpDir(c));
        mkdirs(logDir(c));
        writeText(stampFile, stamp);
    }

    private static String installStamp(Context c) {
        try {
            PackageInfo pi = c.getPackageManager().getPackageInfo(c.getPackageName(), 0);
            return pi.getLongVersionCode() + "/" + pi.lastUpdateTime;
        } catch (PackageManager.NameNotFoundException e) {
            return "unknown";
        }
    }

    /** 展開済みの ArcRec の版(src/bootstrap.php の LITE_VERSION)。分からなければ null */
    static String arcrecVersion(Context c) {
        File f = new File(webRoot(c), "src/bootstrap.php");
        if (!f.isFile()) {
            return null;
        }
        try {
            Matcher m = Pattern.compile("define\\('LITE_VERSION',\\s*'([^']+)'\\)").matcher(readText(f));
            return m.find() ? m.group(1) : null;
        } catch (IOException e) {
            return null;
        }
    }

    // ---- ファイル操作 ----

    /** ZIP を dir に展開する。dir の外を指す項目(../ など)があれば中止する。 */
    static int unzip(InputStream in, File dir) throws IOException {
        mkdirs(dir);
        String base = dir.getCanonicalPath() + File.separator;
        int count = 0;
        try (ZipInputStream zip = new ZipInputStream(in)) {
            ZipEntry e;
            while ((e = zip.getNextEntry()) != null) {
                File out = new File(dir, e.getName());
                if (!out.getCanonicalPath().startsWith(base)) {
                    throw new IOException("ZIP に不正なパスがあります: " + e.getName());
                }
                if (e.isDirectory()) {
                    mkdirs(out);
                } else {
                    mkdirs(out.getParentFile());
                    try (OutputStream os = new FileOutputStream(out)) {
                        pipe(zip, os);
                    }
                    count++;
                }
            }
        }
        return count;
    }

    static void copy(InputStream in, File to) throws IOException {
        mkdirs(to.getParentFile());
        try (OutputStream os = new FileOutputStream(to)) {
            pipe(in, os);
        }
    }

    static long pipe(InputStream in, OutputStream out) throws IOException {
        byte[] buf = new byte[64 * 1024];
        long total = 0;
        int n;
        while ((n = in.read(buf)) > 0) {
            out.write(buf, 0, n);
            total += n;
        }
        return total;
    }

    static void mkdirs(File dir) throws IOException {
        if (dir != null && !dir.isDirectory() && !dir.mkdirs()) {
            throw new IOException("フォルダを作れません: " + dir);
        }
    }

    static void deleteRecursively(File f) {
        if (f == null || !f.exists()) {
            return;
        }
        File[] children = f.isDirectory() ? f.listFiles() : null;
        if (children != null) {
            for (File ch : children) {
                deleteRecursively(ch);
            }
        }
        //noinspection ResultOfMethodCallIgnored
        f.delete();
    }

    static String readText(File f) throws IOException {
        return new String(Files.readAllBytes(f.toPath()), StandardCharsets.UTF_8);
    }

    static void writeText(File f, String s) throws IOException {
        mkdirs(f.getParentFile());
        Files.write(f.toPath(), s.getBytes(StandardCharsets.UTF_8));
    }
}
