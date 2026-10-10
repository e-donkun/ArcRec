<?php
require __DIR__ . '/_layout.php';

use Lite\Entries;
use Lite\Pdf;
use Lite\Results;

$type = $_GET['type'] ?? '';
// 結果PDFは公開(?c=記録会コード)。スコアカード・QRは管理者のみ。
if ($type === 'results') {
    public_comp();
} else {
    require_admin();
}

switch ($type) {
    case 'results':
        $data = Pdf::results(Results::byCategory());
        $name = 'results.pdf';
        break;
    case 'scorecard_blank':
    case 'scorecard_filled':
        $entries = array_values(array_filter(Entries::all(), function ($e) {
            if ($e['status']) {
                return false;
            }
            if (!empty($_GET['t']) && (int)$e['target'] !== (int)$_GET['t']) {
                return false;
            }
            if (!empty($_GET['id']) && (int)$e['id'] !== (int)$_GET['id']) {
                return false;
            }
            return true;
        }));
        $qr = empty($_GET['noqr']) ? server_base_url() : null;
        $data = Pdf::scorecards($entries, $type === 'scorecard_filled', $qr);
        $name = 'scorecards.pdf';
        break;
    case 'qr':
        $data = Pdf::qrSheet(server_base_url());
        $name = 'target-qr.pdf';
        break;
    default:
        http_response_code(404);
        exit('not found');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-store');
echo $data;
