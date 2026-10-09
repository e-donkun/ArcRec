<?php
/*
GitHub の Webhook を受けて、サーバー(さくらのレンタルサーバなど)のこのフォルダで git pull する。
main に push されたら自動で最新になる。

設定(config.local.php。Git には入らない):
  'deploy_secret'  => 'GitHub の Webhook に入れた Secret と同じ文字列'(必須。未設定なら 404 で何もしない)
  'deploy_branch'  => 'main'(省略時 main)
  'deploy_ssh_key' => '/home/ユーザー名/.ssh/ianseo-lite_deploy'(非公開リポジトリを SSH で取るときの鍵。省略時は git の既定のまま)
GitHub 側: Settings → Webhooks → Add webhook
  Payload URL  = https://<サーバー>/<このフォルダ>/deploy.php
  Content type = application/json
  Secret       = deploy_secret と同じ
  イベント      = Just the push event
結果は data/deploy.log に残る(data/ は Web から見えない)。
*/
require_once __DIR__ . '/src/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

function deploy_exit($code, $msg)
{
    http_response_code($code);
    echo $msg, "\n";
    exit;
}

function deploy_log($msg)
{
    @file_put_contents(Lite\Db::dataDir() . '/deploy.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

$secret = (string)lite_config('deploy_secret', '');
if ($secret === '') {
    deploy_exit(404, 'Not Found');   // 自動更新を使わない設定
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_exit(405, 'POST only');
}

// 署名(X-Hub-Signature-256 = HMAC-SHA256)を確かめる。合わなければ何もしない
$body = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $sig)) {
    deploy_log('署名が合わないリクエストを拒否 (' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ')');
    deploy_exit(403, 'Bad signature');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    deploy_log('ping を受信(Webhook の設定は OK)');
    deploy_exit(200, 'pong');
}
if ($event !== 'push') {
    deploy_exit(202, 'Ignored event: ' . $event);
}
$payload = json_decode($body, true);
if (!is_array($payload) && isset($_POST['payload'])) {
    $payload = json_decode((string)$_POST['payload'], true);   // Content type が form のとき
}
$branch = (string)lite_config('deploy_branch', 'main');
$ref = is_array($payload) ? (string)($payload['ref'] ?? '') : '';
if ($ref !== 'refs/heads/' . $branch) {
    deploy_exit(202, 'Ignored ref: ' . $ref);
}

// 同時に2つ走らないように
$lock = fopen(Lite\Db::dataDir() . '/deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    deploy_exit(409, 'Another deploy is running');
}

$env = [
    'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
    'HOME' => getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : ''),
    'LANG' => 'C',
    'GIT_TERMINAL_PROMPT' => '0',
];
$key = (string)lite_config('deploy_ssh_key', '');
if ($key !== '') {
    // 鍵と known_hosts を明示する(Web サーバーから動かすと ~/.ssh/config が読まれないことがあるため)
    $env['GIT_SSH_COMMAND'] = 'ssh -i ' . escapeshellarg($key) . ' -o IdentitiesOnly=yes -o BatchMode=yes'
        . ' -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg(Lite\Db::dataDir() . '/known_hosts');
}

$cmd = 'git -C ' . escapeshellarg(__DIR__) . ' pull --ff-only origin ' . escapeshellarg($branch) . ' 2>&1';
$proc = proc_open($cmd, [1 => ['pipe', 'w']], $pipes, __DIR__, $env);
if (!is_resource($proc)) {
    deploy_log('git を起動できません(PHP から外部コマンドを実行できない設定かもしれません)');
    deploy_exit(500, 'Cannot run git');
}
$out = trim(stream_get_contents($pipes[1]));
fclose($pipes[1]);
$rc = proc_close($proc);

$after = is_array($payload) ? substr((string)($payload['after'] ?? ''), 0, 7) : '';
deploy_log(($rc === 0 ? 'OK' : 'NG(' . $rc . ')') . ' push ' . $after . ' → git pull: ' . str_replace("\n", ' | ', $out));
deploy_exit($rc === 0 ? 200 : 500, $out);
