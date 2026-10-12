package io.github.edonkun.arcrec;

import android.content.Context;
import android.net.Uri;

import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.zip.ZipEntry;
import java.util.zip.ZipOutputStream;

/**
 * 記録会データ(LITE_DATA_DIR)のバックアップと復元。
 * サーバーを止めてから呼ぶ(SQLite のファイルを書き込み途中で写さないため)。
 */
final class DataBackup {
    private DataBackup() {
    }

    static boolean hasCompetitions(Context c) {
        File[] f = new File(AppFiles.dataDir(c), "competitions").listFiles();
        return f != null && f.length > 0;
    }

    /** データ一式を ZIP にしてダウンロード/ArcRec に保存する。ログイン情報(sessions/)は入れない。 */
    static Uri backup(Context c, String prefix) throws IOException {
        File root = AppFiles.dataDir(c);
        List<File> files = new ArrayList<>();
        collect(root, root, files);
        if (files.isEmpty()) {
            throw new IOException("保存するデータがありません");
        }
        String name = prefix + "-" + new SimpleDateFormat("yyyyMMdd-HHmmss", Locale.ROOT).format(new Date()) + ".zip";
        return SavedFiles.saveToDownloads(c, name, "application/zip", out -> {
            ZipOutputStream zip = new ZipOutputStream(out);
            String base = root.getAbsolutePath() + File.separator;
            for (File f : files) {
                zip.putNextEntry(new ZipEntry(f.getAbsolutePath().substring(base.length()).replace(File.separatorChar, '/')));
                try (InputStream in = new FileInputStream(f)) {
                    AppFiles.pipe(in, zip);
                }
                zip.closeEntry();
            }
            zip.finish();
        });
    }

    private static void collect(File root, File dir, List<File> out) {
        File[] list = dir.listFiles();
        if (list == null) {
            return;
        }
        for (File f : list) {
            if (dir.equals(root) && f.getName().equals("sessions")) {
                continue;
            }
            if (f.isDirectory()) {
                collect(root, f, out);
            } else if (f.isFile()) {
                out.add(f);
            }
        }
    }

    /**
     * ZIP からデータを戻す。いまのデータは消える(呼ぶ前に backup しておく)。
     * ArcRec のバックアップ(competitions/ に記録会がある)でなければ何も変えずに例外にする。
     */
    static int restore(Context c, Uri zipUri) throws IOException {
        File data = AppFiles.dataDir(c);
        File incoming = new File(c.getFilesDir(), "arcrec-data.restore");
        File old = new File(c.getFilesDir(), "arcrec-data.old");
        AppFiles.deleteRecursively(incoming);
        AppFiles.deleteRecursively(old);
        try (InputStream in = c.getContentResolver().openInputStream(zipUri)) {
            if (in == null) {
                throw new IOException("ファイルを読めません");
            }
            AppFiles.unzip(in, incoming);
        } catch (IOException e) {
            AppFiles.deleteRecursively(incoming);
            throw e;
        }
        File[] comps = new File(incoming, "competitions").listFiles((d, n) -> n.endsWith(".sqlite"));
        if (comps == null || comps.length == 0) {
            AppFiles.deleteRecursively(incoming);
            throw new IOException("ArcRec のバックアップではありません(competitions/ に記録会がありません)");
        }
        if (data.exists() && !data.renameTo(old)) {
            AppFiles.deleteRecursively(incoming);
            throw new IOException("いまのデータを退避できませんでした");
        }
        if (!incoming.renameTo(data)) {
            //noinspection ResultOfMethodCallIgnored
            old.renameTo(data);
            throw new IOException("データを戻せませんでした");
        }
        AppFiles.deleteRecursively(old);
        return comps.length;
    }
}
