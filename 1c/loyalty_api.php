<?php
declare(strict_types=1);
// Server-to-server import only. Never expose this key to browser JavaScript.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors','0');
function respond(int $status,array $data): void {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE);
    exit;
}
function invalid(string $field): void {
    respond(400,['ok'=>false,'error'=>'invalid_field','field'=>$field]);
}
function strField(array $r,string $key,int $max): ?string {
    $v=$r[$key]??null;
    if ($v===null || $v==='') return null;
    if (!is_string($v) || !preg_match('/\A[^\p{C}]{1,'.$max.'}\z/u',$v)) invalid($key);
    return trim($v)==='' ? null : trim($v);
}
function guid(array $r,string $key,bool $required=false): ?string {
    $v=strField($r,$key,36);
    if ($v===null && !$required) return null;
    if ($v===null || !preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i',$v) || $v==='00000000-0000-0000-0000-000000000000') invalid($key);
    return strtolower($v);
}
function decimalField(array $r,string $key,float $max): ?string {
    $v=$r[$key]??null;
    if ($v===null) return null;
    if (!is_numeric($v) || !is_finite((float)$v) || (float)$v<0 || (float)$v>$max) invalid($key);
    return number_format((float)$v,2,'.','');
}
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') respond(405,['ok'=>false,'error'=>'method']);
    $cfg=require __DIR__.'/config.php';
    // Dedicated key: add loyalty_import_key to the private hosting config.
    $key=$cfg['loyalty_import_key']??'';
    if (!is_string($key) || strlen($key)<32 || $key==='REPLACE_WITH_RANDOM_SECRET_KEY' ||
        !hash_equals($key,$_SERVER['HTTP_X_API_KEY']??'')) respond(403,['ok'=>false,'error'=>'auth']);
    $raw=file_get_contents('php://input',false,null,0,524289);
    if ($raw===false || strlen($raw)>524288) respond(413,['ok'=>false,'error'=>'size']);
    $d=json_decode($raw,true);
    if (!is_array($d) || ($d['action']??'')!=='upsert_cards') invalid('action');
    $cards=$d['cards']??null;
    if (!is_array($cards) || !array_is_list($cards) || count($cards)<1 || count($cards)>200) invalid('cards');
    $rows=[]; $seen=[];
    foreach ($cards as $r) {
        if (!is_array($r)) invalid('cards');
        $id=guid($r,'card_guid',true);
        if (isset($seen[$id])) invalid('duplicate_card_guid');
        $seen[$id]=true;
        $date=strField($r,'birth_date',10);
        if ($date!==null && (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$date,$m) || (int)$m[1]<1000 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))) invalid('birth_date');
        $active=$r['is_active']??null;
        if (!in_array($active,[true,false,0,1],true)) invalid('is_active');
        $phone=strField($r,'phone',20);
        $rows[]=[$id,guid($r,'owner_guid'),strField($r,'card_number',100),strField($r,'barcode',100),
            strField($r,'last_name',100),strField($r,'first_name',100),strField($r,'middle_name',100),$phone,$date,
            strField($r,'loyalty_program',150),decimalField($r,'cashback_percent',100),
            decimalField($r,'bonus_balance',9999999999999.99),(int)$active];
    }
    $pdo=new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->beginTransaction();
    $q=$pdo->prepare('INSERT INTO loyalty_cards_1c
        (card_guid,owner_guid,card_number,barcode,last_name,first_name,middle_name,phone,birth_date,loyalty_program,cashback_percent,bonus_balance,is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        is_active=IF(user_id IS NOT NULL AND NOT(owner_guid <=> VALUES(owner_guid)),0,VALUES(is_active)),
        card_number=VALUES(card_number),barcode=VALUES(barcode),last_name=VALUES(last_name),
        first_name=VALUES(first_name),middle_name=VALUES(middle_name),phone=VALUES(phone),
        birth_date=VALUES(birth_date),loyalty_program=VALUES(loyalty_program),
        cashback_percent=VALUES(cashback_percent),bonus_balance=VALUES(bonus_balance),
        owner_guid=IF(user_id IS NULL,VALUES(owner_guid),owner_guid),updated_at=NOW()');
    foreach ($rows as $row) $q->execute($row);
    $pdo->commit();
    respond(200,['ok'=>true,'count'=>count($rows)]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('loyalty import failed: '.get_class($e));
    respond(500,['ok'=>false,'error'=>'server']);
}
