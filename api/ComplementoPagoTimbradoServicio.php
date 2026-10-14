<?php

declare(strict_types=1);

require_once __DIR__ . '/ComplementoPagoXmlGenerador.php';
require_once __DIR__ . '/CfdiTimbradoServicio.php';
require_once __DIR__ . '/FacturaDocumentoLocalizador.php';

final class ComplementoPagoTimbradoServicio
{
    public function __construct(private readonly PDO $conexion, private readonly string $raiz)
    {
    }

    /** @return array{uuid:string,ambiente:string} */
    public function timbrar(int $id, int $empresa, int $usuario): array
    {
        if ($id <= 0 || $empresa <= 0 || $usuario <= 0) {
            throw new ComplementoPagoXmlException('El complemento o la sesión no son válidos.');
        }
        $nombreBloqueo = 'timbrar-complemento-' . $id;
        $consultaBloqueo = $this->conexion->prepare('SELECT GET_LOCK(:nombre, 10)');
        $consultaBloqueo->execute([':nombre' => $nombreBloqueo]);
        if ((int) $consultaBloqueo->fetchColumn() !== 1) {
            throw new ComplementoPagoXmlException('Otro timbrado de este complemento está en proceso.');
        }
        try {
            $complemento = $this->obtenerPendiente($id, $empresa, $usuario);
            if (trim((string) ($complemento['uuid'] ?? '')) !== '') {
                return ['uuid' => (string) $complemento['uuid'], 'ambiente' => 'registrado'];
            }
            $servicioPac = new CfdiTimbradoServicio($this->conexion,
                $this->raiz . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'sinfirma-comp',
                $this->raiz . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'firmados-comp');
            $rutaFirmada = $this->raiz . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR
                . 'firmados-comp' . DIRECTORY_SEPARATOR . 'XML-factura-' . $id . '.xml';
            // Si el PAC timbró pero el UPDATE falló, recuperar el XML antes de reintentar.
            if (is_file($rutaFirmada)) {
                $firmado = file_get_contents($rutaFirmada);
                if (!is_string($firmado) || !$this->esPagoFirmado($firmado, $complemento, $servicioPac)) {
                    throw new ComplementoPagoXmlException('Existe un XML firmado local que no coincide con este complemento. Requiere revisión manual.');
                }
                $metadatos = $servicioPac->analizarXmlTimbrado($firmado, (string) $complemento['emisor_rfc']);
                $this->persistirFirmado($id, $empresa, $firmado, $metadatos);
                return ['uuid' => $metadatos['uuid'], 'ambiente' => 'recuperado'];
            }

            $relaciones = $this->obtenerRelaciones($id, $empresa);
            $localizador = new FacturaDocumentoLocalizador($this->raiz);
            foreach ($relaciones as &$relacion) {
                if ((int) $relacion['factura_empresa'] !== $empresa
                    || (int) ($relacion['cancelado'] ?? 0) !== 0
                    || (int) ($relacion['desactivado'] ?? 0) !== 0
                    || strtoupper(trim((string) $relacion['factura_uuid'])) !== strtoupper(trim((string) $relacion['uuid_rel']))) {
                    throw new ComplementoPagoXmlException('Una factura relacionada está cancelada, desactivada o cambió de UUID.');
                }
                $xml = $localizador->obtenerXml((int) $relacion['id_factura'], $relacion);
                if ($xml === null) {
                    throw new ComplementoPagoXmlException('No se encontró el XML timbrado de la factura ' . (int) $relacion['id_factura'] . '.');
                }
                $relacion['xml_origen'] = $xml;
            }
            unset($relacion);
            $fechaEmision = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s');
            $fechaPago = (new DateTimeImmutable((string) $complemento['fecha_pago']))->format('Y-m-d\TH:i:s');
            $datos = [
                'serie' => trim((string) ($complemento['serie'] ?? '')),
                'folio' => (string) $complemento['folio'], 'fecha_emision' => $fechaEmision,
                'fecha_pago' => $fechaPago, 'monto_total' => (string) $complemento['total_factura'],
                'forma_pago' => str_pad(trim((string) $complemento['forma_clave']), 2, '0', STR_PAD_LEFT),
                'emisor_rfc' => strtoupper(trim((string) $complemento['emisor_rfc'])),
                'emisor_nombre' => trim((string) $complemento['emisor_nombre']),
                'emisor_cp' => trim((string) $complemento['emisor_cp']),
                'emisor_regimen' => substr(trim((string) $complemento['emisor_regimen']), 0, 3),
                'receptor_rfc' => strtoupper(trim((string) $complemento['receptor_rfc'])),
                'receptor_nombre' => trim((string) $complemento['receptor_nombre']),
                'receptor_cp' => trim((string) $complemento['receptor_cp']),
                'receptor_regimen' => (string) $complemento['receptor_regimen'],
            ];
            $xmlSinFirma = (new ComplementoPagoXmlGenerador())->generar($datos, $relaciones);
            try {
                $resultado = $servicioPac->timbrarXml($xmlSinFirma, $datos['emisor_rfc'],
                    trim((string) $complemento['usuario_pac']), (string) $complemento['password_pac']);
            } catch (CfdiTimbradoException $error) {
                $this->registrarError($id, $empresa, $error->getMessage());
                if ($this->resultadoPacIndeterminado($error->getMessage())) {
                    throw new ComplementoPagoXmlException('No se pudo confirmar la respuesta del PAC. El complemento quedó en “Revisar timbrado”; no lo reenvíes hasta verificar si ya recibió un UUID.');
                }
                throw $error;
            }
            // El PAC pudo haber timbrado incluso si la respuesta no supera nuestra validación.
            // Resguardar primero evita que un reintento automático lo envíe dos veces.
            try {
                $this->guardarAtomico($rutaFirmada, $resultado['xml']);
            } catch (ComplementoPagoXmlException $error) {
                $this->marcarRevision($id, $empresa, 'El PAC respondió correctamente, pero no se pudo resguardar el XML.');
                throw new ComplementoPagoXmlException('El PAC respondió, pero no se pudo guardar el XML. El complemento quedó en “Revisar timbrado”; no lo reenvíes.');
            }
            if (!$this->esPagoFirmado($resultado['xml'], $complemento, $servicioPac)) {
                $this->marcarRevision($id, $empresa, 'El XML devuelto por el PAC no coincide con el complemento.');
                throw new ComplementoPagoXmlException('El PAC devolvió un CFDI que no corresponde al complemento enviado. Requiere revisión manual.');
            }
            $this->persistirFirmado($id, $empresa, $resultado['xml'], $resultado['metadatos']);
            return ['uuid' => $resultado['metadatos']['uuid'], 'ambiente' => $resultado['ambiente']];
        } finally {
            $liberar = $this->conexion->prepare('SELECT RELEASE_LOCK(:nombre)');
            $liberar->execute([':nombre' => $nombreBloqueo]);
        }
    }

    /** @return array<string,mixed> */
    private function obtenerPendiente(int $id, int $empresa, int $usuario): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT fc.id, fc.uuid, fc.status, fc.serie, fc.folio, fc.fecha_pago, fc.total_factura,
                    e.rfc AS emisor_rfc, e.razon AS emisor_nombre, e.cp AS emisor_cp,
                    e.regimen AS emisor_regimen, e.usuario AS usuario_pac, e.password_r AS password_pac,
                    cc.rfc AS receptor_rfc, cc.razon_social AS receptor_nombre,
                    cc.cp AS receptor_cp, cc.regimen_fiscal AS receptor_regimen,
                    fp.clave AS forma_clave
             FROM facturas_complementos fc
             INNER JOIN empresas e ON e.id = fc.razon
             INNER JOIN claves_cortas cc ON cc.id = CAST(fc.id_clave_corta AS UNSIGNED) AND cc.id_cliente = fc.nombre
             INNER JOIN usuarios u ON u.id = :usuario AND u.cliente = cc.id_cliente
             INNER JOIN formas_pago fp ON fp.id = CAST(fc.forma_pago AS UNSIGNED)
             WHERE fc.id = :id AND fc.razon = :empresa LIMIT 1"
        );
        $consulta->execute([':usuario' => $usuario, ':id' => $id, ':empresa' => $empresa]);
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$fila) throw new ComplementoPagoXmlException('El complemento no existe o no está asignado a tu usuario y empresa.');
        if (trim((string) ($fila['uuid'] ?? '')) === '' && (string) $fila['status'] !== 'Pendiente') {
            throw new ComplementoPagoXmlException('Solo se pueden timbrar complementos nuevos en estado Pendiente.');
        }
        return $fila;
    }

    /** @return array<int,array<string,mixed>> */
    private function obtenerRelaciones(int $id, int $empresa): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT r.id_factura, r.uuid_rel, r.monto, r.num_pago, r.saldo, r.restante,
                    f.razon AS factura_empresa, f.uuid AS factura_uuid, f.cancelado, f.desactivado,
                    f.xml_firmado, f.token, f.serie, f.folio, e.razon AS empresa_nombre
             FROM facturar_rel_complementos r
             INNER JOIN facturas f ON f.id = r.id_factura
             INNER JOIN empresas e ON e.id = f.razon
             WHERE r.id_complemento = :id AND f.razon = :empresa ORDER BY r.id'
        );
        $consulta->execute([':id' => $id, ':empresa' => $empresa]);
        $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $conteo = $this->conexion->prepare('SELECT COUNT(*) FROM facturar_rel_complementos WHERE id_complemento = :id');
        $conteo->execute([':id' => $id]);
        if ($filas === [] || count($filas) > 20 || count($filas) !== (int) $conteo->fetchColumn()) {
            throw new ComplementoPagoXmlException('El complemento no tiene relaciones válidas con facturas de esta empresa.');
        }
        return $filas;
    }

    /** @param array<string,mixed> $complemento */
    private function esPagoFirmado(string $xml, array $complemento, CfdiTimbradoServicio $pac): bool
    {
        try {
            $pac->analizarXmlTimbrado($xml, (string) $complemento['emisor_rfc']);
            $dom = new DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) return false;
            $raiz = $dom->documentElement;
            $xpath = new DOMXPath($dom);
            $receptor = strtoupper(trim((string) $xpath->evaluate('string(/*[local-name()="Comprobante"]/*[local-name()="Receptor"]/@Rfc)')));
            return $raiz instanceof DOMElement && $raiz->getAttribute('TipoDeComprobante') === 'P'
                && $raiz->getAttribute('Folio') === (string) $complemento['folio']
                && preg_match('/^0(?:\.0+)?$/D', $raiz->getAttribute('Total')) === 1
                && $receptor === strtoupper(trim((string) $complemento['receptor_rfc']))
                && $dom->getElementsByTagNameNS('http://www.sat.gob.mx/Pagos20', 'Pagos')->length === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,string> $metadatos */
    private function persistirFirmado(int $id, int $empresa, string $xml, array $metadatos): void
    {
        $consulta = $this->conexion->prepare(
            "UPDATE facturas_complementos SET uuid = :uuid, xml_firmado = :xml,
                    fecha_timbrado = :fecha, hora_timbrado = :hora,
                    num_certificado = :numero, certificado_fact = :certificado,
                    sello_cfd = :sello, num_certificado_sat = :numero_sat, sello_sat = :sello_sat,
                    status = 'Timbrada', status_pago = 'Timbrada', status_error = NULL
             WHERE id = :id AND razon = :empresa AND (uuid IS NULL OR TRIM(uuid) = '')"
        );
        $consulta->execute([':uuid' => $metadatos['uuid'], ':xml' => $xml,
            ':fecha' => $metadatos['fecha_timbrado'], ':hora' => $metadatos['hora_timbrado'],
            ':numero' => $metadatos['num_certificado'], ':certificado' => $metadatos['certificado'],
            ':sello' => $metadatos['sello_cfd'], ':numero_sat' => $metadatos['num_certificado_sat'],
            ':sello_sat' => $metadatos['sello_sat'], ':id' => $id, ':empresa' => $empresa]);
        if ($consulta->rowCount() !== 1) {
            throw new ComplementoPagoXmlException('El complemento cambió mientras se registraba el timbre. El XML quedó resguardado para recuperarlo.');
        }
    }

    private function registrarError(int $id, int $empresa, string $mensaje): void
    {
        try {
            $dudoso = $this->resultadoPacIndeterminado($mensaje);
            $consulta = $this->conexion->prepare(
                "UPDATE facturas_complementos SET status_error = :error,
                    status = CASE WHEN :dudoso = 1 THEN 'Revisar timbrado' ELSE status END
                 WHERE id = :id AND razon = :empresa AND status = 'Pendiente' AND (uuid IS NULL OR TRIM(uuid) = '')"
            );
            $consulta->execute([':error' => substr(preg_replace('/[\r\n\t]+/', ' ', $mensaje) ?: '', 0, 100),
                ':dudoso' => $dudoso ? 1 : 0, ':id' => $id, ':empresa' => $empresa]);
        } catch (Throwable $error) {
            error_log('No se pudo registrar el rechazo del PAC para complemento ' . $id . ': ' . $error->getMessage());
        }
    }

    private function resultadoPacIndeterminado(string $mensaje): bool
    {
        return preg_match('/^No fue posible comunicarse|^IOFacturo respondió con el código HTTP|^IOFacturo devolvió|^La respuesta de IOFacturo|^El XML timbrado/u', $mensaje) === 1;
    }

    private function marcarRevision(int $id, int $empresa, string $mensaje): void
    {
        try {
            $consulta = $this->conexion->prepare(
                "UPDATE facturas_complementos SET status = 'Revisar timbrado', status_error = :error
                 WHERE id = :id AND razon = :empresa AND status = 'Pendiente' AND (uuid IS NULL OR TRIM(uuid) = '')"
            );
            $consulta->execute([':error' => substr($mensaje, 0, 100), ':id' => $id, ':empresa' => $empresa]);
        } catch (Throwable $error) {
            error_log('No se pudo marcar para revisión el complemento ' . $id . ': ' . $error->getMessage());
        }
    }

    private function guardarAtomico(string $ruta, string $contenido): void
    {
        $directorio = dirname($ruta);
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new ComplementoPagoXmlException('No se pudo crear el directorio de XML del complemento.');
        }
        $temporal = $ruta . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporal, $contenido, LOCK_EX) === false || !rename($temporal, $ruta)) {
                throw new ComplementoPagoXmlException('No se pudo guardar el XML del complemento.');
            }
        } finally {
            if (is_file($temporal)) @unlink($temporal);
        }
    }
}
