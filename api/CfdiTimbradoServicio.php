<?php

declare(strict_types=1);

final class CfdiTimbradoException extends RuntimeException
{
}

final class CfdiTimbradoServicio
{
    private const CFDI_NAMESPACE = 'http://www.sat.gob.mx/cfd/4';
    private const TFD_NAMESPACE = 'http://www.sat.gob.mx/TimbreFiscalDigital';
    private const URL_SANDBOX = 'https://sandboxtunelapi.iofacturo.mx/api/Tunel';
    private const URL_PRODUCCION = 'https://tunelapi.iofacturo.mx/api/Tunel';

    public function __construct(
        private readonly PDO $conexion,
        private readonly string $directorioSinFirma,
        private readonly string $directorioFirmados
    ) {
    }

    /**
     * Autentica al emisor, timbra el pre-CFDI y persiste tanto el XML como sus datos fiscales.
     *
     * @return array{uuid: string, fecha_timbrado: string, ambiente: string, xml: array{archivo: string, url: string}}
     */
    public function timbrar(int $facturaId, int $empresaId, ?int $usuarioId = null): array
    {
        if ($facturaId <= 0 || $empresaId <= 0) {
            throw new InvalidArgumentException('La factura o la empresa no son válidas para timbrar.');
        }

        $this->prepararDirectorio($this->directorioFirmados);
        $bloqueoRuta = rtrim($this->directorioFirmados, '/\\') . DIRECTORY_SEPARATOR . '.timbrar-' . $facturaId . '.lock';
        $bloqueo = fopen($bloqueoRuta, 'c');
        if ($bloqueo === false || !flock($bloqueo, LOCK_EX)) {
            if (is_resource($bloqueo)) {
                fclose($bloqueo);
            }
            throw new CfdiTimbradoException('No fue posible bloquear temporalmente la factura para timbrarla.');
        }

        try {
            $factura = $this->obtenerFacturaYCredenciales($facturaId, $empresaId);
            if (trim((string) ($factura['uuid'] ?? '')) !== '') {
                return $this->resultadoExistente($facturaId, $factura);
            }

            $rfc = strtoupper(trim((string) ($factura['rfc'] ?? '')));
            $usuarioPac = trim((string) ($factura['usuario_pac'] ?? ''));
            $passwordPac = (string) ($factura['password_pac'] ?? '');
            if ($rfc === '' || $usuarioPac === '' || $passwordPac === '') {
                throw new CfdiTimbradoException('La empresa activa no tiene completas sus credenciales de IOFacturo.');
            }

            $archivo = 'XML-factura-' . $facturaId . '.xml';
            $rutaFirmada = rtrim($this->directorioFirmados, '/\\') . DIRECTORY_SEPARATOR . $archivo;
            if (is_file($rutaFirmada)) {
                $xmlRecuperado = file_get_contents($rutaFirmada);
                if (is_string($xmlRecuperado) && trim($xmlRecuperado) !== '') {
                    try {
                        $metadatosRecuperados = $this->analizarXmlTimbrado($xmlRecuperado, $rfc);
                        $this->guardarTimbrado($facturaId, $usuarioId, $xmlRecuperado, $metadatosRecuperados);
                        return $this->crearResultado($facturaId, $metadatosRecuperados, $this->obtenerAmbiente());
                    } catch (CfdiTimbradoException | PDOException $error) {
                        error_log('No fue posible recuperar el XML timbrado de la factura ' . $facturaId . ': ' . $error->getMessage());
                    }
                }
            }

            $rutaSinFirma = rtrim($this->directorioSinFirma, '/\\') . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
            $xmlSinFirma = is_file($rutaSinFirma) ? file_get_contents($rutaSinFirma) : false;
            if (!is_string($xmlSinFirma) || trim($xmlSinFirma) === '') {
                throw new CfdiTimbradoException('No se encontró el XML previo de la factura.');
            }

            $ambiente = $this->obtenerAmbiente();
            $baseUrl = $ambiente === 'pruebas' ? self::URL_SANDBOX : self::URL_PRODUCCION;

            try {
                $autenticacion = $this->enviarJson($baseUrl . '/AutenticaUsuario', [
                    'Rfc' => $rfc,
                    'Usuario' => $usuarioPac,
                    'Password' => $passwordPac,
                ]);
                $token = trim((string) ($autenticacion['Mensaje'] ?? ''));
                if ($token === '') {
                    throw new CfdiTimbradoException($this->mensajePac($autenticacion, 'IOFacturo no devolvió un token de autenticación.'));
                }

                $respuesta = $this->enviarJson($baseUrl . '/TimbrarDocumento', [
                    'Comprobante' => $xmlSinFirma,
                    'Token' => $token,
                ]);
                $xmlTimbrado = trim((string) ($respuesta['Mensaje'] ?? ''));
                if ($xmlTimbrado === '') {
                    throw new CfdiTimbradoException($this->mensajePac($respuesta, 'IOFacturo no devolvió el XML timbrado.'));
                }
                $metadatos = $this->analizarXmlTimbrado($xmlTimbrado, $rfc);
            } catch (CfdiTimbradoException $error) {
                $this->registrarFallo($facturaId, $usuarioId, $error->getMessage());
                throw $error;
            }

            $this->guardarAtomico($rutaFirmada, $xmlTimbrado);
            $this->guardarTimbrado($facturaId, $usuarioId, $xmlTimbrado, $metadatos);
            return $this->crearResultado($facturaId, $metadatos, $ambiente);
        } finally {
            flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
            @unlink($bloqueoRuta);
        }
    }

    /**
     * @return array{uuid: string, fecha_timbrado: string, hora_timbrado: string, num_certificado: string, certificado: string, sello_cfd: string, num_certificado_sat: string, sello_sat: string}
     */
    public function analizarXmlTimbrado(string $xml, string $rfcEsperado): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new CfdiTimbradoException('La extensión DOM de PHP es necesaria para leer el XML timbrado.');
        }

        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument('1.0', 'UTF-8');
        try {
            $cargado = $documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
        if (!$cargado || !$documento->documentElement instanceof DOMElement) {
            throw new CfdiTimbradoException('IOFacturo devolvió una respuesta que no es un XML válido.');
        }

        $comprobante = $documento->documentElement;
        if ($comprobante->localName !== 'Comprobante' || $comprobante->namespaceURI !== self::CFDI_NAMESPACE) {
            throw new CfdiTimbradoException('La respuesta de IOFacturo no contiene un CFDI 4.0 válido.');
        }

        $emisores = $documento->getElementsByTagNameNS(self::CFDI_NAMESPACE, 'Emisor');
        $emisor = $emisores->item(0);
        if (!$emisor instanceof DOMElement || strtoupper(trim($emisor->getAttribute('Rfc'))) !== strtoupper(trim($rfcEsperado))) {
            throw new CfdiTimbradoException('El RFC del XML timbrado no corresponde a la empresa activa.');
        }

        $timbres = $documento->getElementsByTagNameNS(self::TFD_NAMESPACE, 'TimbreFiscalDigital');
        $timbre = $timbres->item(0);
        if (!$timbre instanceof DOMElement) {
            throw new CfdiTimbradoException('La respuesta de IOFacturo no contiene el Timbre Fiscal Digital.');
        }

        $uuid = strtoupper(trim($timbre->getAttribute('UUID')));
        $fechaOriginal = trim($timbre->getAttribute('FechaTimbrado'));
        if ($uuid === '' || preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid) !== 1) {
            throw new CfdiTimbradoException('IOFacturo devolvió un timbre sin UUID válido.');
        }
        try {
            $fecha = new DateTimeImmutable($fechaOriginal);
        } catch (Throwable) {
            throw new CfdiTimbradoException('IOFacturo devolvió una fecha de timbrado inválida.');
        }

        $metadatos = [
            'uuid' => $uuid,
            'fecha_timbrado' => $fecha->format('Y-m-d'),
            'hora_timbrado' => $fecha->format('H:i:s'),
            'num_certificado' => trim($comprobante->getAttribute('NoCertificado')),
            'certificado' => trim($comprobante->getAttribute('Certificado')),
            'sello_cfd' => trim($timbre->getAttribute('SelloCFD')),
            'num_certificado_sat' => trim($timbre->getAttribute('NoCertificadoSAT')),
            'sello_sat' => trim($timbre->getAttribute('SelloSAT')),
        ];
        foreach (['num_certificado', 'certificado', 'sello_cfd', 'num_certificado_sat', 'sello_sat'] as $campo) {
            if ($metadatos[$campo] === '') {
                throw new CfdiTimbradoException('El XML timbrado está incompleto; falta el campo ' . $campo . '.');
            }
        }
        return $metadatos;
    }

    /** @return array<string, mixed> */
    private function obtenerFacturaYCredenciales(int $facturaId, int $empresaId): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT f.id, f.uuid, f.fecha_timbrado, f.hora_timbrado,
                    e.rfc, e.usuario AS usuario_pac, e.password_r AS password_pac
             FROM facturas f
             INNER JOIN empresas e ON e.id = f.razon
             WHERE f.id = :factura AND f.razon = :empresa
             LIMIT 1'
        );
        $consulta->execute([':factura' => $facturaId, ':empresa' => $empresaId]);
        $factura = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!is_array($factura)) {
            throw new CfdiTimbradoException('La factura no existe o no pertenece a la empresa activa.');
        }
        return $factura;
    }

    private function obtenerAmbiente(): string
    {
        $modo = strtolower(trim((string) $this->conexion->query(
            'SELECT facturacion FROM configuracion ORDER BY id LIMIT 1'
        )->fetchColumn()));
        return in_array($modo, ['test', 'prueba', 'pruebas', 'sandbox', 'desarrollo'], true)
            ? 'pruebas'
            : 'produccion';
    }

    /** @param array<string, string> $datos @return array<string, mixed> */
    private function enviarJson(string $url, array $datos): array
    {
        if (!extension_loaded('curl')) {
            throw new CfdiTimbradoException('La extensión cURL de PHP es necesaria para comunicarse con IOFacturo.');
        }
        $contenido = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new CfdiTimbradoException('No fue posible iniciar la conexión con IOFacturo.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $contenido,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $respuesta = curl_exec($curl);
        $codigoHttp = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorCurl = curl_error($curl);
        curl_close($curl);

        if (!is_string($respuesta)) {
            throw new CfdiTimbradoException('No fue posible comunicarse con IOFacturo' . ($errorCurl !== '' ? ': ' . $errorCurl : '.'));
        }
        if ($codigoHttp < 200 || $codigoHttp >= 300) {
            throw new CfdiTimbradoException('IOFacturo respondió con el código HTTP ' . $codigoHttp . '.');
        }
        try {
            $decodificada = json_decode($respuesta, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CfdiTimbradoException('IOFacturo devolvió una respuesta JSON inválida.');
        }
        if (!is_array($decodificada)) {
            throw new CfdiTimbradoException('IOFacturo devolvió una respuesta vacía.');
        }
        return $decodificada;
    }

    /** @param array<string, mixed> $respuesta */
    private function mensajePac(array $respuesta, string $predeterminado): string
    {
        $mensaje = trim((string) ($respuesta['Mensaje'] ?? $respuesta['mensaje'] ?? ''));
        if ($mensaje === '') {
            return $predeterminado;
        }
        $partes = array_values(array_filter(array_map('trim', explode('|', $mensaje)), static fn (string $parte): bool => $parte !== ''));
        return $this->limitar(end($partes) ?: $mensaje, 190);
    }

    /** @param array<string, string> $metadatos */
    private function guardarTimbrado(int $facturaId, ?int $usuarioId, string $xml, array $metadatos): void
    {
        $this->conexion->beginTransaction();
        try {
            $actualizar = $this->conexion->prepare(
                "UPDATE facturas SET
                    num_certificado = :num_certificado,
                    certificado_fact = :certificado,
                    uuid = :uuid,
                    fecha_timbrado = :fecha_timbrado,
                    hora_timbrado = :hora_timbrado,
                    sello_cfd = :sello_cfd,
                    num_certificado_sat = :num_certificado_sat,
                    sello_sat = :sello_sat,
                    xml_firmado = :xml,
                    usuario_timbra = :usuario,
                    status = 'Timbrada',
                    status_error = NULL
                 WHERE id = :factura AND (uuid IS NULL OR TRIM(uuid) = '')"
            );
            $actualizar->bindValue(':num_certificado', $metadatos['num_certificado']);
            $actualizar->bindValue(':certificado', $metadatos['certificado']);
            $actualizar->bindValue(':uuid', $metadatos['uuid']);
            $actualizar->bindValue(':fecha_timbrado', $metadatos['fecha_timbrado']);
            $actualizar->bindValue(':hora_timbrado', $metadatos['hora_timbrado']);
            $actualizar->bindValue(':sello_cfd', $metadatos['sello_cfd']);
            $actualizar->bindValue(':num_certificado_sat', $metadatos['num_certificado_sat']);
            $actualizar->bindValue(':sello_sat', $metadatos['sello_sat']);
            $actualizar->bindValue(':xml', $xml);
            $this->vincularUsuario($actualizar, ':usuario', $usuarioId);
            $actualizar->bindValue(':factura', $facturaId, PDO::PARAM_INT);
            $actualizar->execute();
            if ($actualizar->rowCount() !== 1) {
                throw new CfdiTimbradoException('La factura cambió mientras se procesaba el timbrado.');
            }

            $this->insertarLog($facturaId, $usuarioId, $metadatos['uuid'], 'Se timbró la factura correctamente.');
            $this->conexion->commit();
        } catch (Throwable $error) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $error;
        }
    }

    private function registrarFallo(int $facturaId, ?int $usuarioId, string $mensaje): void
    {
        $mensaje = $this->limitar(preg_replace('/[\r\n\t]+/', ' ', trim($mensaje)) ?: 'El PAC rechazó la factura.', 190);
        try {
            $this->conexion->beginTransaction();
            $actualizar = $this->conexion->prepare(
                "UPDATE facturas SET status = 'Error', status_error = :error, usuario_timbra = :usuario
                 WHERE id = :factura AND (uuid IS NULL OR TRIM(uuid) = '')"
            );
            $actualizar->bindValue(':error', $mensaje);
            $this->vincularUsuario($actualizar, ':usuario', $usuarioId);
            $actualizar->bindValue(':factura', $facturaId, PDO::PARAM_INT);
            $actualizar->execute();
            $this->insertarLog($facturaId, $usuarioId, 'NA', 'La factura falló: ' . $mensaje);
            $this->conexion->commit();
        } catch (Throwable $error) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            error_log('No fue posible registrar el error de timbrado: ' . $error->getMessage());
        }
    }

    private function insertarLog(int $facturaId, ?int $usuarioId, string $uuid, string $mensaje): void
    {
        $consulta = $this->conexion->prepare(
            'INSERT INTO log_timbrado (mensaje, uuid, usuario_timbro, fecha, id_factura)
             VALUES (:mensaje, :uuid, :usuario, NOW(), :factura)'
        );
        $consulta->bindValue(':mensaje', $this->limitar($mensaje, 200));
        $consulta->bindValue(':uuid', $this->limitar($uuid, 100));
        $this->vincularUsuario($consulta, ':usuario', $usuarioId);
        $consulta->bindValue(':factura', $facturaId, PDO::PARAM_INT);
        $consulta->execute();
    }

    private function vincularUsuario(PDOStatement $consulta, string $parametro, ?int $usuarioId): void
    {
        if ($usuarioId !== null && $usuarioId > 0) {
            $consulta->bindValue($parametro, $usuarioId, PDO::PARAM_INT);
        } else {
            $consulta->bindValue($parametro, null, PDO::PARAM_NULL);
        }
    }

    /** @param array<string, mixed> $factura @return array{uuid: string, fecha_timbrado: string, ambiente: string, xml: array{archivo: string, url: string}} */
    private function resultadoExistente(int $facturaId, array $factura): array
    {
        $fecha = trim((string) ($factura['fecha_timbrado'] ?? ''));
        $hora = trim((string) ($factura['hora_timbrado'] ?? ''));
        return [
            'uuid' => trim((string) $factura['uuid']),
            'fecha_timbrado' => $fecha . ($hora !== '' ? 'T' . $hora : ''),
            'ambiente' => $this->obtenerAmbiente(),
            'xml' => [
                'archivo' => 'XML-factura-' . $facturaId . '.xml',
                'url' => 'api/facturas.php?accion=descargar_xml&id=' . $facturaId . '&tipo=timbrado',
            ],
        ];
    }

    /** @param array<string, string> $metadatos @return array{uuid: string, fecha_timbrado: string, ambiente: string, xml: array{archivo: string, url: string}} */
    private function crearResultado(int $facturaId, array $metadatos, string $ambiente): array
    {
        return [
            'uuid' => $metadatos['uuid'],
            'fecha_timbrado' => $metadatos['fecha_timbrado'] . 'T' . $metadatos['hora_timbrado'],
            'ambiente' => $ambiente,
            'xml' => [
                'archivo' => 'XML-factura-' . $facturaId . '.xml',
                'url' => 'api/facturas.php?accion=descargar_xml&id=' . $facturaId . '&tipo=timbrado',
            ],
        ];
    }

    private function guardarAtomico(string $ruta, string $contenido): void
    {
        $temporal = $ruta . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporal, $contenido, LOCK_EX) === false || !rename($temporal, $ruta)) {
                throw new CfdiTimbradoException('No fue posible guardar el XML timbrado.');
            }
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }

    private function prepararDirectorio(string $directorio): void
    {
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new CfdiTimbradoException('No fue posible crear el directorio de XML timbrados.');
        }
        if (!is_writable($directorio)) {
            throw new CfdiTimbradoException('El directorio de XML timbrados no tiene permisos de escritura.');
        }
    }

    private function limitar(string $valor, int $longitud): string
    {
        return function_exists('mb_substr') ? mb_substr($valor, 0, $longitud, 'UTF-8') : substr($valor, 0, $longitud);
    }
}
