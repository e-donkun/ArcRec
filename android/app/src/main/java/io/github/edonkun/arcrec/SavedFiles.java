package io.github.edonkun.arcrec;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.os.CancellationSignal;
import android.os.Environment;
import android.os.ParcelFileDescriptor;
import android.print.PageRange;
import android.print.PrintAttributes;
import android.print.PrintDocumentAdapter;
import android.print.PrintDocumentInfo;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.widget.Toast;

import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;

/** 「ダウンロード/ArcRec」への保存と、保存したファイルを開く・共有する・印刷する */
final class SavedFiles {
    static final String FOLDER = Environment.DIRECTORY_DOWNLOADS + "/ArcRec";

    interface Writer {
        void write(OutputStream out) throws IOException;
    }

    private SavedFiles() {
    }

    /** ダウンロード/ArcRec にファイルを作って中身を書く。同じ名前があれば端末が名前を変える。 */
    static Uri saveToDownloads(Context c, String name, String mime, Writer writer) throws IOException {
        ContentResolver cr = c.getContentResolver();
        ContentValues v = new ContentValues();
        v.put(MediaStore.Downloads.DISPLAY_NAME, name);
        v.put(MediaStore.Downloads.MIME_TYPE, mime);
        v.put(MediaStore.Downloads.RELATIVE_PATH, FOLDER);
        v.put(MediaStore.Downloads.IS_PENDING, 1);
        Uri uri = cr.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
        if (uri == null) {
            throw new IOException("ダウンロードフォルダーに保存できません");
        }
        try (OutputStream out = cr.openOutputStream(uri)) {
            if (out == null) {
                throw new IOException("ダウンロードフォルダーに保存できません");
            }
            writer.write(out);
        } catch (IOException | RuntimeException e) {
            cr.delete(uri, null, null);
            throw e;
        }
        v.clear();
        v.put(MediaStore.Downloads.IS_PENDING, 0);
        cr.update(uri, v, null, null);
        return uri;
    }

    static void open(Activity a, Uri uri, String mime) {
        Intent i = new Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime)
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        try {
            a.startActivity(i);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(a, "開けるアプリがありません。共有から別のアプリに送ってください。", Toast.LENGTH_LONG).show();
        }
    }

    static void share(Activity a, Uri uri, String mime, String title) {
        Intent i = new Intent(Intent.ACTION_SEND).setType(mime)
                .putExtra(Intent.EXTRA_STREAM, uri)
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        a.startActivity(Intent.createChooser(i, title));
    }

    /** PDF を Android の印刷画面に渡す(プリンターへの印刷・PDF として保存) */
    static void printPdf(Activity a, Uri uri, String jobName) {
        PrintManager pm = a.getSystemService(PrintManager.class);
        pm.print(jobName, new PdfAdapter(a.getApplicationContext(), uri, jobName),
                new PrintAttributes.Builder().setMediaSize(PrintAttributes.MediaSize.ISO_A4).build());
    }

    private static final class PdfAdapter extends PrintDocumentAdapter {
        private final Context context;
        private final Uri uri;
        private final String name;

        PdfAdapter(Context context, Uri uri, String name) {
            this.context = context;
            this.uri = uri;
            this.name = name;
        }

        @Override
        public void onLayout(PrintAttributes oldAttributes, PrintAttributes newAttributes,
                             CancellationSignal cancellationSignal, LayoutResultCallback callback, Bundle extras) {
            if (cancellationSignal.isCanceled()) {
                callback.onLayoutCancelled();
                return;
            }
            PrintDocumentInfo info = new PrintDocumentInfo.Builder(name)
                    .setContentType(PrintDocumentInfo.CONTENT_TYPE_DOCUMENT)
                    .setPageCount(PrintDocumentInfo.PAGE_COUNT_UNKNOWN)
                    .build();
            callback.onLayoutFinished(info, !newAttributes.equals(oldAttributes));
        }

        @Override
        public void onWrite(PageRange[] pages, ParcelFileDescriptor destination,
                            CancellationSignal cancellationSignal, WriteResultCallback callback) {
            try (InputStream in = context.getContentResolver().openInputStream(uri);
                 OutputStream out = new FileOutputStream(destination.getFileDescriptor())) {
                if (in == null) {
                    throw new IOException("PDF を読めません");
                }
                AppFiles.pipe(in, out);
                callback.onWriteFinished(new PageRange[]{PageRange.ALL_PAGES});
            } catch (IOException e) {
                callback.onWriteFailed(e.getMessage());
            }
        }
    }
}
