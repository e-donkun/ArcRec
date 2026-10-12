package io.github.edonkun.arcrec;

import android.app.Activity;
import android.graphics.Typeface;
import android.os.Bundle;
import android.widget.ScrollView;
import android.widget.TextView;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.util.HashSet;
import java.util.Set;
import java.util.zip.ZipEntry;
import java.util.zip.ZipInputStream;

/** APK に同梱したライセンス文書を表示する。 */
public class LicenseActivity extends Activity {
    private static final Set<String> ARCREC_LICENSES = new HashSet<String>() {{
        add("LICENSE.txt");
        add("NOTICE.md");
        add("lib/tcpdf/LICENSE.TXT");
    }};

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (getActionBar() != null) {
            getActionBar().setDisplayHomeAsUpEnabled(true);
        }

        TextView text = Ui.text(this, loadLicenses(), 12);
        text.setTypeface(Typeface.MONOSPACE);
        text.setTextIsSelectable(true);
        int padding = Ui.dp(this, 12);
        text.setPadding(padding, padding, padding, padding);
        ScrollView scroll = new ScrollView(this);
        scroll.addView(text);
        setContentView(scroll);
    }

    @Override
    public boolean onOptionsItemSelected(android.view.MenuItem item) {
        if (item.getItemId() == android.R.id.home) {
            finish();
            return true;
        }
        return super.onOptionsItemSelected(item);
    }

    private String loadLicenses() {
        StringBuilder output = new StringBuilder();
        output.append("PHP acknowledgment:\n")
            .append("This product includes PHP software, freely available from <http://www.php.net/software/>.\n");
        try {
            appendArchive("arcrec.zip", false, output);
            appendArchive("php-android-runtime.zip", true, output);
        } catch (IOException e) {
            output.append("ライセンス文書を読み込めませんでした: ").append(e.getMessage());
        }
        return output.length() == 0 ? "同梱のライセンス文書がありません。" : output.toString();
    }

    private void appendArchive(String asset, boolean runtimeLicenses, StringBuilder output) throws IOException {
        try (ZipInputStream zip = new ZipInputStream(getAssets().open(asset))) {
            ZipEntry entry;
            while ((entry = zip.getNextEntry()) != null) {
                String name = entry.getName();
                boolean include = runtimeLicenses
                    ? (name.startsWith("licenses/") || name.equals("PACKAGE-MANIFEST.tsv")) && !entry.isDirectory()
                        : ARCREC_LICENSES.contains(name);
                if (include) {
                    ByteArrayOutputStream bytes = new ByteArrayOutputStream();
                    AppFiles.pipe(zip, bytes);
                    output.append("\n\n===== ").append(name).append(" =====\n\n")
                            .append(new String(bytes.toByteArray(), StandardCharsets.UTF_8));
                }
                zip.closeEntry();
            }
        }
    }
}