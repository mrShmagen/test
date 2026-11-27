<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors','0');
function reply(int $status,array $body): void {
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') reply(405,['ok'=>false,'error'=>'method']);
    $cfg=require __DIR__.'/config.php';
    $key=$cfg['loyalty_cashier_key']??'';
    $pepper=$cfg['loyalty_link_pepper']??'';
    if (!is_string($key)||strlen($key)<32||$key==='REPLACE_WITH_RANDOM_SECRET_KEY'||
        !hash_equals($key,$_SERVER['HTTP_X_API_KEY']??'')) reply(403,['ok'=>false,'error'=>'auth']);
    if (!is_string($pepper)||strlen($pepper)<32||$pepper==='REPLACE_WITH_RANDOM_SECRET_KEY'||$pepper===$key)
        reply(500,['ok'=>false,'error'=>'configuration']);
    $raw=file_get_contents('php://input',false,null,0,4097);
    if ($raw===false||strlen($raw)>4096) reply(413,['ok'=>false,'error'=>'size']);
    $d=json_decode($raw,true);
    $guid=$d['card_guid']??null;
    $owner=$d['owner_guid']??null;
    if (!is_array($d)||($d['action']??'')!=='issue'||!is_string($guid)||
        !preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i',$guid)||!is_string($owner)||
        !preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i',$owner))
        reply(400,['ok'=>false,'error'=>'request']);
    $pdo=new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT id,owner_guid,user_id,is_active,barcode,phone FROM loyalty_cards_1c WHERE card_guid=? FOR UPDATE');
    $q->execute([strtolower($guid)]);
    $card=$q->fetch(PDO::FETCH_ASSOC);
    if (!$card||(int)$card['is_active']!==1||empty($card['owner_guid'])||empty($card['barcode'])||empty($card['phone'])) {
        $pdo->rollBack();reply(409,['ok'=>false,'error'=>'card_unavailable']);
    }
    if (strtolower($card['owner_guid'])!==strtolower($owner)) {
        $pdo->rollBack();reply(409,['ok'=>false,'error'=>'owner_mismatch']);
    }
    if ($card['user_id']!==null) {
        $pdo->rollBack();reply(409,['ok'=>false,'error'=>'already_linked']);
    }
    $id=(int)$card['id'];
    $q=$pdo->prepare('SELECT COUNT(*) FROM loyalty_link_log WHERE card_id=? AND event_type=\'issued\' AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
    $q->execute([$id]);
    if ((int)$q->fetchColumn()>=3) {
        $pdo->rollBack();reply(429,['ok'=>false,'error'=>'issue_limit']);
    }
    // Delete only the locked card's previous code. A duplicate generated hash
    // must never update a different card's row.
    $q=$pdo->prepare('DELETE FROM loyalty_link_codes WHERE card_id=?');
    $q->execute([$id]);
    $q=$pdo->prepare('INSERT INTO loyalty_link_codes(card_id,code_hash,owner_guid,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE))');
    $inserted=false;
    for ($i=0;$i<12;$i++) {
        $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        // Globally unique code lookup, domain-separated from legacy hashes.
        $hash=hash_hmac('sha256','link-v2:'.$code,$pepper,true);
        try {$q->execute([$id,$hash,$card['owner_guid']]);$inserted=true;break;}
        catch(PDOException $e){if ((int)($e->errorInfo[1]??0)!==1062) throw $e;}
    }
    if (!$inserted) {$pdo->rollBack();reply(503,['ok'=>false,'error'=>'try_again']);}
    $q=$pdo->prepare("INSERT INTO loyalty_link_log(card_id,event_type) VALUES (?,'issued')");
    $q->execute([$id]);
    $pdo->commit();
    reply(200,['ok'=>true,'code'=>$code,'expires_in'=>900]);
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    error_log('loyalty link issue failed: '.get_class($e));
    reply(500,['ok'=>false,'error'=>'server']);
}
