<?php
// 参加者向けの結果ページ(ログイン不要)。大会設定で「結果を公開」にした大会だけ見られる。
//   results.php            … 公開している大会の一覧
//   results.php?c=大会コード … その大会の予選結果(スマホモードの速報と同じ画面)
require __DIR__ . '/admin/_layout.php';
require __DIR__ . '/admin/_results.php';

use Lite\Competitions;

if (isset($_GET['c'])) {
    results_page(public_comp(), true);
    exit;
}

// 公開している大会の一覧(新しい順。サンプルは最後)
$list = [];
foreach (Competitions::all() as $c) {
    if (Competitions::readSetting($c['code'], 'results_public') === '1') {
        $list[] = $c;
    }
}
if (count($list) === 1) {
    redirect('results.php?c=' . rawurlencode($list[0]['code']));
}
page_head('予選結果', '', ['public' => true, 'base' => 'admin/']);
if (!$list) {
    echo '<div class="sp-empty">公開している結果はありません</div>';
} else {
    echo '<p class="sp-note">大会を選んでください。</p><div class="sp-list">';
    foreach ($list as $c) {
        echo '<a class="sp-card tap" href="results.php?c=' . h(rawurlencode($c['code'])) . '">'
            . '<div class="sp-name">' . h($c['name'] ?: $c['code']) . ($c['sample'] ? '<span class="sp-tag">サンプル</span>' : '') . '</div>'
            . '<div class="sp-sub">' . h(implode(' / ', array_filter([$c['preset_label'], $c['entries'] . '人',
                $c['last_score'] ? '最終更新 ' . substr($c['last_score'], 0, 16) : '']))) . '</div></a>';
    }
    echo '</div>';
}
page_foot();
