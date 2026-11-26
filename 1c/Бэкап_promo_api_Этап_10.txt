<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

function reply(int $http, array $body): void {
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $http, string $error, string $message): void {
    reply($http, ['ok'=>false,'error'=>$error,'message'=>$message]);
}
function success(string $state): void {
    reply(200, ['ok'=>true,'status'=>$state]);
}
try {
    $cfg = require __DIR__.'/config.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405,'method','POST required');
    $key = $cfg['api_key'];
    if (strlen($key)<32 || $key==='REPLACE_WITH_RANDOM_SECRET_KEY' ||
        !hash_equals($key, $_SERVER['HTTP_X_API_KEY'] ?? ''))
        fail(403,'auth','Ошибка авторизации');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw)>65536) fail(413,'size','Запрос слишком большой');
    $d = json_decode($raw, true);
    if (!is_array($d)) fail(400,'json','Некорректный JSON');
    $action = $d['action'] ?? '';
    $code = $d['code'] ?? '';
    $token = $d['reservation_id'] ?? '';
    if (!is_string($action) || !in_array($action,['create','create_batch','status','reserve','release','redeem'],true))
        fail(400,'action','Неизвестная операция');
    if ($action!=='create_batch' && (!is_string($code) || !preg_match('/\A[\p{Lu}\p{N}_-]{1,10}\z/u',$code)))
        fail(400,'code','Некорректный промокод');
    if (in_array($action,['reserve','release','redeem'],true) &&
        (!is_string($token) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',$token)))
        fail(400,'token','Некорректный идентификатор резерва');
    $pdo = new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['database'].';charset=utf8mb4',
        $cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($action==='create_batch') {
        $id=$d['batch_id'] ?? '';
        $codes=$d['codes'] ?? null;
        if (!is_string($id) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',$id))
            fail(400,'batch_id','Некорректный идентификатор партии');
        $id=strtolower($id);
        if (!is_array($codes) || count($codes)<1 || count($codes)>1000 || array_keys($codes)!==range(0,count($codes)-1))
            fail(400,'batch_size','Допустимо от 1 до 1000 кодов в партии');
        foreach ($codes as $c) {
            if (!is_string($c) || !preg_match('/\A[A-Z0-9]{1,10}\z/',$c))
                fail(400,'code','Некорректный код в партии');
        }
        if (count(array_unique($codes,SORT_STRING))!==count($codes))
            fail(400,'duplicates','Повторяющиеся коды внутри партии');
        sort($codes,SORT_STRING);
        $hash=hash('sha256',json_encode($codes));
        $pdo->beginTransaction();
        // Serialize retries by the same batch ID. A committed row always means
        // that every code in this batch was inserted in the same transaction.
        $q=$pdo->prepare('INSERT INTO promo_batches(batch_id,payload_hash,code_count) VALUES (?,?,?) ON DUPLICATE KEY UPDATE batch_id=batch_id');
        $q->execute([$id,$hash,count($codes)]);
        $new=$q->rowCount()===1;
        $q=$pdo->prepare('SELECT payload_hash,code_count FROM promo_batches WHERE batch_id=? FOR UPDATE');
        $q->execute([$id]);
        $batch=$q->fetch(PDO::FETCH_ASSOC);
        if (!hash_equals($batch['payload_hash'],$hash)) {
            $pdo->rollBack();
            fail(409,'batch_mismatch','Идентификатор партии уже использован для другого набора кодов');
        }
        if ($new) {
            $insert=$pdo->prepare('INSERT INTO promo_codes(code) VALUES (?)');
            foreach ($codes as $c) {
                try { $insert->execute([$c]); }
                catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0)===1062) {
                        $pdo->rollBack();
                        reply(409,['ok'=>false,'error'=>'code_exists','message'=>'Код уже существует в MySQL: '.$c,'code'=>$c]);
                    }
                    throw $e;
                }
            }
        }
        $pdo->commit();
        reply(200,['ok'=>true,'batch_id'=>$id,'count'=>(int)$batch['code_count'],'replayed'=>!$new]);
    }
    if ($action==='create') {
        // Idempotent registration; never reset a reserved or used promo.
        $q=$pdo->prepare('INSERT INTO promo_codes (code) VALUES (?) ON DUPLICATE KEY UPDATE code=code');
        $q->execute([$code]);
    }
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT status,reservation_id FROM promo_codes WHERE code=? FOR UPDATE');
    $q->execute([$code]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); fail(404,'not_found','Промокод не зарегистрирован на сервере'); }
    $state=$row['status'];
    $owner=$row['reservation_id'];
    if ($action==='reserve') {
        if ($state==='used') { $pdo->rollBack(); fail(409,'used','Промокод уже использован'); }
        if ($state==='reserved' && $owner!==$token) { $pdo->rollBack(); fail(409,'reserved','Промокод уже зарезервирован другой кассой'); }
        if ($state==='active') {
            $q=$pdo->prepare("UPDATE promo_codes SET status='reserved',reservation_id=?,reserved_at=NOW() WHERE code=?");
            $q->execute([$token,$code]); $state='reserved';
        }
    } elseif ($action==='release') {
        if ($state==='used') { $pdo->rollBack(); fail(409,'used','Использованный промокод нельзя освободить'); }
        if ($state==='reserved') {
            if ($owner!==$token) { $pdo->rollBack(); fail(409,'owner','Резерв принадлежит другой кассе'); }
            $q=$pdo->prepare("UPDATE promo_codes SET status='active',reservation_id=NULL,reserved_at=NULL WHERE code=?");
            $q->execute([$code]); $state='active';
        }
    } elseif ($action==='redeem') {
        if ($state==='used' && $owner===$token) { /* retry after lost reply */ }
        elseif ($state==='reserved' && $owner===$token) {
            $q=$pdo->prepare("UPDATE promo_codes SET status='used',used_at=NOW() WHERE code=?");
            $q->execute([$code]); $state='used';
        } else { $pdo->rollBack(); fail(409,'owner','Нет действующего резерва этого чека'); }
    }
    $pdo->commit();
    success($state);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Promo API: '.$e->getMessage());
    fail(500,'server','Ошибка сервера промокодов');
}
