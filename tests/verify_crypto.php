<?php
/* Ejecutar: php tests/verify_crypto.php (sin BD ni configuracion del sistema). */
require dirname(__FILE__) . '/../api/passkeys_crypto.php';
$f = json_decode(file_get_contents(dirname(__FILE__) . '/fixtures.json'), true);
$passed = 0;
function check($label, $fn, $reject) {
    global $passed;
    $failed = false;
    try { $fn(); } catch (Exception $e) { $failed = true; }
    if ($failed !== $reject) { fwrite(STDERR, 'FAIL: ' . $label . "\n"); exit(1); }
    $passed++; echo 'OK: ' . $label . "\n";
}
$id = pk_unb64($f['id'],1023);
$record = pk_registration($f['registration'],$f['challenge'],$f['origin'],$f['rp'],$id);
$record['backup_eligible'] = $record['be']; $record['sign_count'] = 0; $record['user_handle'] = 'example-handle';
check('registro ES256 y firma valida', function() use ($f,$record) { pk_assertion($f['assertion'],$f['challenge'],$f['origin'],$f['rp'],$record); }, false);
check('otro origen', function() use ($f,$record) { pk_assertion($f['assertion'],$f['challenge'],'https://otro.example',$f['rp'],$record); }, true);
check('otro reto', function() use ($f,$record) { pk_assertion($f['assertion'],'otro-reto',$f['origin'],$f['rp'],$record); }, true);
check('otro RP ID', function() use ($f,$record) { pk_assertion($f['assertion'],$f['challenge'],$f['origin'],'otro.example',$record); }, true);
check('firma manipulada', function() use ($f,$record) { $r=$f['assertion'];$sig=pk_unb64($r['signature'],256);$sig[strlen($sig)-1]=chr(ord($sig[strlen($sig)-1])^1);$r['signature']=pk_b64($sig);pk_assertion($r,$f['challenge'],$f['origin'],$f['rp'],$record); }, true);
check('sin verificacion UV', function() use ($f,$record) { $r=$f['assertion'];$a=pk_unb64($r['authenticatorData'],16384);$a[32]=chr(1);$r['authenticatorData']=pk_b64($a);pk_assertion($r,$f['challenge'],$f['origin'],$f['rp'],$record); }, true);
check('contador repetido', function() use ($f,$record) { $record['sign_count']=1;pk_assertion($f['assertion'],$f['challenge'],$f['origin'],$f['rp'],$record); }, true);
check('userHandle de otra cuenta', function() use ($f,$record) { $r=$f['assertion'];$r['userHandle']=pk_b64('otro');pk_assertion($r,$f['challenge'],$f['origin'],$f['rp'],$record); }, true);
check('registro con ID distinto', function() use ($f) { pk_registration($f['registration'],$f['challenge'],$f['origin'],$f['rp'],'otro-id'); }, true);
check('crossOrigin no permitido', function() use ($f,$record) { $r=$f['assertion'];$c=json_decode(pk_unb64($r['clientDataJSON'],8192),true);$c['crossOrigin']=true;$r['clientDataJSON']=pk_b64(json_encode($c));pk_assertion($r,$f['challenge'],$f['origin'],$f['rp'],$record); }, true);
check('CBOR truncado', function() { $o=0;pk_cbor("\xa1",$o,0); }, true);
check('CBOR con claves duplicadas', function() { $o=0;pk_cbor("\xa2\x01\x01\x01\x02",$o,0); }, true);
echo $passed . " pruebas correctas.\n";
