<?php
/* Verificador restringido: ES256 / P-256, attestation none, UV obligatoria.
 * Sin Composer. Compatible con sintaxis PHP 5.4; necesita OpenSSL EC.
 * No implementa validacion de fabricante/attestation certificada.
 */
function pk_equal($a, $b) {
    if (!is_string($a) || !is_string($b) || strlen($a) !== strlen($b)) return false;
    $diff = 0;
    for ($i = 0; $i < strlen($a); $i++) $diff |= ord($a[$i]) ^ ord($b[$i]);
    return $diff === 0;
}
function pk_random($length) {
    $strong = false;
    $bytes = openssl_random_pseudo_bytes($length, $strong);
    if ($bytes === false || !$strong || strlen($bytes) !== $length) throw new Exception('No hay generador aleatorio seguro.');
    return $bytes;
}
function pk_b64($bytes) { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
function pk_unb64($value, $max) {
    if (!is_string($value) || $value === '' || strlen($value) > $max * 2 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) throw new Exception('Codificacion invalida.');
    $raw = base64_decode(strtr($value, '-_', '+/'), true);
    if ($raw === false || strlen($raw) > $max || pk_b64($raw) !== $value) throw new Exception('Codificacion invalida.');
    return $raw;
}
class PKCborMap { public $values; function __construct($values) { $this->values = $values; } }
class PKCborBytes { public $value; function __construct($value) { $this->value = $value; } }
/* CBOR con limites, sin longitudes indefinidas, tags o claves duplicadas. */
function pk_take($data, &$offset, $length) {
    if ($length < 0 || $offset + $length > strlen($data)) throw new Exception('CBOR truncado.');
    $result = substr($data, $offset, $length); $offset += $length; return $result;
}
function pk_cbor($data, &$offset, $depth) {
    if ($depth > 8) throw new Exception('CBOR demasiado profundo.');
    $head = ord(pk_take($data, $offset, 1)); $major = $head >> 5; $ai = $head & 31;
    if ($major === 7) {
        if ($ai === 20) return false;
        if ($ai === 21) return true;
        if ($ai === 22) return null;
        throw new Exception('CBOR simple no admitido.');
    }
    if ($ai < 24) $length = $ai;
    elseif ($ai === 24) $length = ord(pk_take($data, $offset, 1));
    elseif ($ai === 25) { $n = unpack('nvalue', pk_take($data, $offset, 2)); $length = $n['value']; }
    elseif ($ai === 26) { $n = unpack('Nvalue', pk_take($data, $offset, 4)); $length = $n['value']; }
    else throw new Exception('Longitud CBOR no admitida.');
    if ($major === 0) return $length;
    if ($major === 1) return -1 - $length;
    if ($length > 65536) throw new Exception('CBOR demasiado grande.');
    if ($major === 2) return new PKCborBytes(pk_take($data, $offset, $length));
    if ($major === 3) return pk_take($data, $offset, $length);
    if ($major !== 4 && $major !== 5) throw new Exception('Tipo CBOR no admitido.');
    if ($length > 128) throw new Exception('Demasiados elementos CBOR.');
    $result = array();
    for ($i = 0; $i < $length; $i++) {
        if ($major === 4) $result[] = pk_cbor($data, $offset, $depth + 1);
        else {
            $key = pk_cbor($data, $offset, $depth + 1);
            if (!is_int($key) && !is_string($key)) throw new Exception('Clave CBOR invalida.');
            if (array_key_exists($key, $result)) throw new Exception('Clave CBOR duplicada.');
            $result[$key] = pk_cbor($data, $offset, $depth + 1);
        }
    }
    return $major === 5 ? new PKCborMap($result) : $result;
}
function pk_client($encoded, $type, $challenge, $origin) {
    $raw = pk_unb64($encoded, 8192); $client = json_decode($raw, true);
    if (!is_array($client) || !isset($client['type'], $client['challenge'], $client['origin']) ||
        $client['type'] !== $type || !pk_equal($client['challenge'], $challenge) || $client['origin'] !== $origin ||
        (isset($client['crossOrigin']) && $client['crossOrigin'] !== false) || isset($client['topOrigin'])) throw new Exception('Origen, reto o tipo invalidos.');
    return $raw;
}
function pk_authdata($raw, $rpId, $registration) {
    if (strlen($raw) < 37 || !pk_equal(substr($raw, 0, 32), hash('sha256', $rpId, true))) throw new Exception('RP ID invalido.');
    $flags = ord($raw[32]);
    if (($flags & 1) === 0 || ($flags & 4) === 0) throw new Exception('Se requiere presencia y verificacion del usuario.');
    if (($flags & 16) && !($flags & 8)) throw new Exception('Estado de respaldo invalido.');
    if (($flags & 64) !== ($registration ? 64 : 0)) throw new Exception('Datos del autenticador invalidos.');
    $count = unpack('Nvalue', substr($raw, 33, 4));
    $result = array('count' => $count['value'], 'be' => ($flags & 8) ? 1 : 0, 'bs' => ($flags & 16) ? 1 : 0);
    $offset = 37;
    if ($registration) {
        pk_take($raw, $offset, 16); // AAGUID, no se usa como prueba de fabricante.
        $n = unpack('nvalue', pk_take($raw, $offset, 2));
        if ($n['value'] < 1 || $n['value'] > 1023) throw new Exception('Identificador invalido.');
        $result['credential_id'] = pk_take($raw, $offset, $n['value']);
        $key = pk_cbor($raw, $offset, 0);
        if (!($key instanceof PKCborMap)) throw new Exception('COSE invalido.');
        $key = $key->values;
        if (!isset($key[1], $key[3], $key[-1], $key[-2], $key[-3]) || $key[1] !== 2 || $key[3] !== -7 || $key[-1] !== 1 ||
            !($key[-2] instanceof PKCborBytes) || !($key[-3] instanceof PKCborBytes) || strlen($key[-2]->value) !== 32 || strlen($key[-3]->value) !== 32 || isset($key[-4])) throw new Exception('Solo se admite ES256/P-256 publico.');
        // SubjectPublicKeyInfo ecPublicKey + prime256v1, punto EC sin comprimir.
        $der = pack('H*', '3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $key[-2]->value . $key[-3]->value;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $public = openssl_pkey_get_public($pem);
        if ($public === false) throw new Exception('Punto EC invalido o OpenSSL sin soporte EC.');
        $result['public_key'] = $pem;
    }
    if ($flags & 128) {
        $extensions = pk_cbor($raw, $offset, 0);
        if (!($extensions instanceof PKCborMap)) throw new Exception('Extensiones invalidas.');
    }
    if ($offset !== strlen($raw)) throw new Exception('Datos sobrantes del autenticador.');
    return $result;
}
function pk_registration($response, $challenge, $origin, $rpId, $id) {
    if (!isset($response['clientDataJSON'], $response['attestationObject'])) throw new Exception('Respuesta incompleta.');
    pk_client($response['clientDataJSON'], 'webauthn.create', $challenge, $origin);
    $raw = pk_unb64($response['attestationObject'], 65536); $offset = 0; $att = pk_cbor($raw, $offset, 0);
    if ($offset !== strlen($raw) || !($att instanceof PKCborMap)) throw new Exception('Attestation invalida.');
    $att = $att->values;
    if (!isset($att['fmt'], $att['authData'], $att['attStmt']) || $att['fmt'] !== 'none' || !($att['authData'] instanceof PKCborBytes) ||
        !($att['attStmt'] instanceof PKCborMap) || count($att['attStmt']->values) !== 0) throw new Exception('Se requiere attestation none.');
    $result = pk_authdata($att['authData']->value, $rpId, true);
    if (!pk_equal($result['credential_id'], $id)) throw new Exception('Identificador de credencial distinto.');
    return $result;
}
function pk_assertion($response, $challenge, $origin, $rpId, $record) {
    if (!isset($response['clientDataJSON'], $response['authenticatorData'], $response['signature'])) throw new Exception('Respuesta incompleta.');
    $client = pk_client($response['clientDataJSON'], 'webauthn.get', $challenge, $origin);
    $auth = pk_unb64($response['authenticatorData'], 16384);
    $result = pk_authdata($auth, $rpId, false);
    if ($result['be'] !== (int)$record['backup_eligible']) throw new Exception('Elegibilidad de respaldo distinta.');
    if (isset($response['userHandle']) && $response['userHandle'] !== null && !pk_equal(pk_unb64($response['userHandle'], 64), $record['user_handle'])) throw new Exception('Usuario de credencial distinto.');
    $key = openssl_pkey_get_public($record['public_key']);
    if ($key === false) throw new Exception('Clave publica invalida.');
    $valid = openssl_verify($auth . hash('sha256', $client, true), pk_unb64($response['signature'], 256), $key, OPENSSL_ALGO_SHA256);
    if ($valid !== 1) throw new Exception('Firma invalida.');
    // Credenciales sincronizadas pueden devolver siempre cero. Retroceso no cero: bloquear.
    if (($result['count'] > 0 || $record['sign_count'] > 0) && $result['count'] <= $record['sign_count']) throw new Exception('Contador de firma no creciente. Usa contraseña y 2FA.');
    return $result;
}
