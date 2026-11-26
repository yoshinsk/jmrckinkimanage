<?php
// Path: scripts/operator_lab/guard.php
// 機能: 専用検証ホストだけで実行し、不要な画面を閉じ、全HTMLへ架空データの注意を表示する。
declare(strict_types=1);
if(PHP_SAPI==='cli'){return;}
$host=$_SERVER['HTTP_HOST']??'';
if($host!=='portal-test.gymkinki.com'||!is_file(__DIR__.'/operator-lab.json')){http_response_code(503);exit('検証環境の確認が必要です');}
$marker=json_decode(file_get_contents(__DIR__.'/operator-lab.json'),true,512,JSON_THROW_ON_ERROR);
$name=$_SERVER['PHP_AUTH_USER']??'';$pass=$_SERVER['PHP_AUTH_PW']??'';
// Apache→FPMではPHP_AUTH_*が生成されない構成にも対応し、入口のBasic認証を必ず検査する。
if($name===''){
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(str_starts_with($authorization,'Basic ')){
        $decoded=base64_decode(substr($authorization,6),true);
        if(is_string($decoded)&&str_contains($decoded,':')){[$name,$pass]=explode(':',$decoded,2);}
    }
}
if($name!==($marker['gate_user']??null)||!password_verify($pass,$marker['gate_hash']??'')){
    header('WWW-Authenticate: Basic realm="JMRC operator practice", charset="UTF-8"');http_response_code(401);exit('練習サイトの入口IDとパスワードを入力してください');
}
putenv('JMRC_AUTH_BOOTSTRAP='.__DIR__.'/src/bootstrap.php');putenv('JMRC_AUTH_CONFIG='.__DIR__.'/auth.config.php');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$allowed=['/','/index.php','/manage/','/manage/index.php','/manage/login.php','/manage/club-register.php','/manage/club-finance.php','/manage/logout.php','/entry/login.php','/entry/logout.php'];
if(!in_array($path,$allowed,true)){http_response_code(404);exit('この検証環境ではクラブ登録と会計の画面だけを使用します。操作手順へ戻ってください。');}
header('X-Robots-Tag: noindex, nofollow, noarchive');header('Cache-Control: no-store');header('X-JMRC-Environment: operator-lab');
ob_start(static function(string $body):string{
    foreach(headers_list() as $header){
        if(str_starts_with(strtolower($header),'content-type:')&&!str_contains(strtolower($header),'text/html')){return $body;}
    }
    if(!str_contains($body,'<body')){return $body;}
    $body=str_replace('</head>','<link rel="stylesheet" href="/lab.css"></head>',$body);
    $banner='<aside class="operator-lab-notice" role="note">操作練習専用：すべて架空データです。実在する個人情報を入力せず、実際に振り込まないでください。<a href="/">操作手順へ戻る</a></aside>';
    return preg_replace('/(<body\b[^>]*>)/i','$1'.$banner,$body,1)??$body;
});
