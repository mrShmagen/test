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

// Metadata is optional for compatibility with existing RMK clients.
// Never accept user_id or redemption state from a catalog creation request.
function promoMetadata(array $d): ?array {
    if (!array_key_exists('metadata',$d)) return null;
    $m=$d['metadata'];
    if (!is_array($m)) fail(400,'metadata','Некорректные параметры промокода');
    $from=$m['valid_from'] ?? '';
    $until=$m['valid_until'] ?? '';
    foreach ([$from,$until] as $date) {
        if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',$date))
            fail(400,'date','Некорректная дата');
        [$y,$mo,$day]=array_map('intval',explode('-',$date));
        if ($y<1000 || !checkdate($mo,$day,$y)) fail(400,'date','Некорректная дата');
    }
    if ($until<$from) fail(400,'date','Некорректный период действия');
    $type=$m['promo_type'] ?? '';
    if ($type==='gift') {
        $sku=$m['product_sku'] ?? '';
        $qty=$m['gift_quantity'] ?? null;
        if (!is_string($sku) || trim($sku)==='' || !preg_match('/\A[^\p{C}]{1,50}\z/u',$sku)
            || !is_numeric($qty) || !is_finite((float)$qty) || (float)$qty<=0 || (float)$qty>9999999.999
            || abs((float)$qty-round((float)$qty,3))>0.0000001)
            fail(400,'gift','Некорректные параметры подарка');
        return [$from,$until,$type,null,$sku,number_format((float)$qty,3,'.','')];
    }
    if ($type==='discount') {
        $pct=$m['discount_percent'] ?? null;
        if (!is_numeric($pct) || !is_finite((float)$pct) || (float)$pct<=0 || (float)$pct>100
            || abs((float)$pct-round((float)$pct,2))>0.0000001)
            fail(400,'discount','Некорректный процент скидки');
        return [$from,$until,$type,number_format((float)$pct,2,'.',''),null,null];
    }
    fail(400,'type','Некорректный тип промокода');
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
    if (!is_string($action) || !in_array($action,['create','create_batch','backfill','status','reserve','release','redeem'],true))
        fail(400,'action','Неизвестная операция');
    if (!in_array($action,['create_batch','backfill'],true) && (!is_string($code) || !preg_match('/\A[\p{Lu}\p{N}_-]{1,10}\z/u',$code)))
        fail(400,'code','Некорректный промокод');
    if (in_array($action,['reserve','release','redeem'],true) &&
        (!is_string($token) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',$token)))
        fail(400,'token','Некорректный идентификатор резерва');
    $pdo = new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['database'].';charset=utf8mb4',
        $cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $metadata=in_array($action,['create','create_batch'],true) ? promoMetadata($d) : null;
    if ($action==='backfill') {
        $items=$d['items'] ?? null;
        if (!is_array($items) || count($items)<1 || count($items)>100 || array_keys($items)!==range(0,count($items)-1))
            fail(400,'items','Допустимо от 1 до 100 записей');
        $validated=[];
        foreach ($items as $item) {
            if (!is_array($item)) fail(400,'item','Некорректная запись');
            $c=$item['code'] ?? '';
            if (!is_string($c) || !preg_match('/\A[\p{Lu}\p{N}_-]{1,10}\z/u',$c))
                fail(400,'code','Некорректный промокод');
            if (isset($validated[$c])) fail(400,'duplicates','Повтор кода в запросе');
            $m=promoMetadata($item);
            if ($m===null) fail(400,'metadata','Нет параметров промокода');
            $validated[$c]=$m;
        }
        ksort($validated,SORT_STRING);
        $pdo->beginTransaction();
        $select=$pdo->prepare('SELECT status,valid_from,valid_until,promo_type,discount_percent,product_sku,gift_quantity FROM promo_codes WHERE code=? FOR UPDATE');
        $update=$pdo->prepare('UPDATE promo_codes SET valid_from=?,valid_until=?,promo_type=?,discount_percent=?,product_sku=?,gift_quantity=? WHERE code=?');
        $counts=['updated'=>0,'unchanged'=>0,'missing'=>0,'protected'=>0,'conflict'=>0];
        $issues=[];
        foreach ($validated as $c=>$m) {
            $c=(string)$c;
            $select->execute([$c]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $reason='missing'; }
            elseif ($row['status']!=='active') { $reason='protected'; }
            else {
                $old=[$row['valid_from'],$row['valid_until'],$row['promo_type'],$row['discount_percent'],$row['product_sku'],$row['gift_quantity']];
                if ($old===$m) { $reason='unchanged'; }
                elseif (count(array_filter($old,static function($v){return $v!==null;}))===0) {
                    $update->execute(array_merge($m,[$c]));
                    $reason='updated';
                } else { $reason='conflict'; }
            }
            $counts[$reason]++;
            if (in_array($reason,['missing','protected','conflict'],true)) $issues[]=['code'=>$c,'reason'=>$reason];
        }
        $pdo->commit();
        reply(200,array_merge(['ok'=>true,'issues'=>$issues],$counts));
    }
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
        $legacyHash=hash('sha256',json_encode($codes));
        $hash=$metadata===null ? $legacyHash : hash('sha256',json_encode([$codes,$metadata],JSON_UNESCAPED_UNICODE));
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
            $insert=$pdo->prepare($metadata===null ? 'INSERT INTO promo_codes(code) VALUES (?)' : 'INSERT INTO promo_codes(code,valid_from,valid_until,promo_type,discount_percent,product_sku,gift_quantity) VALUES (?,?,?,?,?,?,?)');
            foreach ($codes as $c) {
                try { $insert->execute($metadata===null ? [$c] : array_merge([$c],$metadata)); }
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
        $q=$pdo->prepare($metadata===null
            ? 'INSERT INTO promo_codes(code) VALUES (?) ON DUPLICATE KEY UPDATE code=code'
            : 'INSERT INTO promo_codes(code,valid_from,valid_until,promo_type,discount_percent,product_sku,gift_quantity) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE code=code');
        $q->execute($metadata===null ? [$code] : array_merge([$code],$metadata));
    }
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT status,reservation_id FROM promo_codes WHERE code=? FOR UPDATE');
    $q->execute([$code]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); fail(404,'not_found','Промокод не зарегистрирован на сервере'); }
    $state=$row['status'];
    $owner=$row['reservation_id'];
    if ($action==='reserve') {
        if ($state==='disabled') { $pdo->rollBack(); fail(409,'disabled','Промокод отключен'); }
        if ($state==='used') { $pdo->rollBack(); fail(409,'used','Промокод уже использован'); }
        if ($state==='reserved' && $owner!==$token) { $pdo->rollBack(); fail(409,'reserved','Промокод уже зарезервирован другой кассой'); }
        if ($state==='active') {
            $q=$pdo->prepare("UPDATE promo_codes SET status='reserved',reservation_id=?,reserved_at=NOW() WHERE code=?");
            $q->execute([$token,$code]); $state='reserved';
        }
    } elseif ($action==='release') {
        if ($state==='disabled') { $pdo->rollBack(); fail(409,'disabled','Промокод отключен'); }
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
