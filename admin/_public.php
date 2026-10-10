<?php
// 参加者向けの公開ページ(ログイン不要)。トップの index.php から public_page() を呼ぶ。
//   ./              … 公開している記録会の一覧(公開が1つだけなら、その結果へ。?list=1 なら一覧のまま)
//   ./?c=記録会コード … その記録会の結果(記録会設定で「結果を公開」にした記録会だけ)。?記録会コード だけでも同じ
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_results.php';

use Lite\Competitions;

function public_page()
{
    if (!isset($_GET['c']) && ($bc = bare_code()) !== null) {
        $_GET['c'] = $bc;   // 「?記録会コード」だけでも「?c=記録会コード」と同じ
    }
    if (isset($_GET['c'])) {
        results_page(public_comp(false), true);   // 一般向け: 管理者でも非公開は見せない
        return;
    }

    // 公開している記録会の一覧(新しい順。サンプルは最後)
    $list = [];
    foreach (Competitions::all() as $c) {
        if (Competitions::readSetting($c['code'], 'results_public') === '1') {
            $list[] = $c;
        }
    }
    if (count($list) === 1 && !isset($_GET['list'])) {   // 1つだけなら、その結果へ(結果の画面の「記録会一覧」から来たときは一覧のまま)
        redirect('./?c=' . rawurlencode($list[0]['code']));
    }
    page_head('記録会一覧', '', ['public' => true, 'base' => 'admin/', 'right' => public_admin_button()]);
    if (!$list) {
        echo '<div class="sp-empty">公開している結果はありません</div>';
    } else {
        echo '<p class="sp-note">結果を閲覧する記録会を選んでください。</p><div class="sp-list">';
        foreach ($list as $c) {
            echo '<a class="sp-card tap" href="./?c=' . h(rawurlencode($c['code'])) . '">'
                . '<div class="sp-name">' . h($c['name'] ?: $c['code']) . ($c['sample'] ? '<span class="sp-tag">サンプル</span>' : '') . '</div>'
                . '<div class="sp-sub">' . h(implode(' / ', array_filter([$c['preset_label'], $c['entries'] . '人',
                    $c['last_score'] ? '最終更新 ' . substr($c['last_score'], 0, 16) : '']))) . '</div></a>';
        }
        echo '</div>';
    }
    page_foot();
}
