package io.github.edonkun.arcrec;

import android.content.Context;
import android.content.res.ColorStateList;
import android.content.res.TypedArray;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.util.TypedValue;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

/** 画面部品を作る小さな道具(レイアウト XML を使わず、コードで組み立てる) */
final class Ui {
    private Ui() {
    }

    static int dp(Context c, float v) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, c.getResources().getDisplayMetrics()));
    }

    static int attrColor(Context c, int attr, int fallback) {
        TypedArray a = c.obtainStyledAttributes(new int[]{attr});
        try {
            return a.getColor(0, fallback);
        } finally {
            a.recycle();
        }
    }

    static int accent(Context c) {
        return attrColor(c, android.R.attr.colorAccent, 0xFFC8102E);
    }

    static int secondaryText(Context c) {
        return attrColor(c, android.R.attr.textColorSecondary, Color.GRAY);
    }

    static LinearLayout.LayoutParams matchWidth() {
        return new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
    }

    static LinearLayout.LayoutParams matchWidth(Context c, int topMarginDp) {
        LinearLayout.LayoutParams lp = matchWidth();
        lp.topMargin = dp(c, topMarginDp);
        return lp;
    }

    static LinearLayout column(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.VERTICAL);
        return l;
    }

    static LinearLayout row(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.HORIZONTAL);
        return l;
    }

    /** 角の丸い枠(カード) */
    static LinearLayout card(Context c) {
        LinearLayout l = column(c);
        GradientDrawable bg = new GradientDrawable();
        bg.setCornerRadius(dp(c, 14));
        bg.setColor(attrColor(c, android.R.attr.colorBackgroundFloating, Color.WHITE));
        int line = secondaryText(c);
        bg.setStroke(dp(c, 1), Color.argb(50, Color.red(line), Color.green(line), Color.blue(line)));
        l.setBackground(bg);
        int p = dp(c, 16);
        l.setPadding(p, p, p, p);
        return l;
    }

    static TextView text(Context c, CharSequence s, float sp) {
        TextView t = new TextView(c);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        return t;
    }

    static TextView bold(Context c, CharSequence s, float sp) {
        TextView t = text(c, s, sp);
        t.setTypeface(Typeface.DEFAULT_BOLD);
        return t;
    }

    static TextView note(Context c, CharSequence s) {
        TextView t = text(c, s, 13);
        t.setTextColor(secondaryText(c));
        t.setLineSpacing(0, 1.15f);
        return t;
    }

    static TextView sectionTitle(Context c, String s) {
        TextView t = bold(c, s, 13);
        t.setTextColor(accent(c));
        t.setLetterSpacing(0.05f);
        return t;
    }

    static Button button(Context c, String label, View.OnClickListener l) {
        Button b = new Button(c);
        b.setText(label);
        b.setAllCaps(false);
        b.setOnClickListener(l);
        return b;
    }

    static Button primaryButton(Context c, String label, View.OnClickListener l) {
        Button b = button(c, label, l);
        b.setBackgroundTintList(ColorStateList.valueOf(accent(c)));
        b.setTextColor(Color.WHITE);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        b.setMinHeight(dp(c, 56));
        return b;
    }

    /** 丸い印(サーバーの状態を色で示す) */
    static View dot(Context c, int sizeDp) {
        View v = new View(c);
        GradientDrawable d = new GradientDrawable();
        d.setShape(GradientDrawable.OVAL);
        d.setColor(Color.GRAY);
        v.setBackground(d);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(dp(c, sizeDp), dp(c, sizeDp));
        lp.rightMargin = dp(c, 10);
        v.setLayoutParams(lp);
        return v;
    }

    static void setDotColor(View dot, int color) {
        ((GradientDrawable) dot.getBackground()).setColor(color);
    }
}
