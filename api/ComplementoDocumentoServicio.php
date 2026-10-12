<?php

declare(strict_types=1);

final class ComplementoDocumentoServicio
{
    private const DIRECTORIOS_XML_EXTERNOS = [
        '/var/www/html/16/ultra/sistema/api/xml/firmados-comp',
        '/var/www/html/16/ultra/sistema/api/xml/sinfirma-comp',
        '/var/www/html/16/dev/app/api/xml/firmados-comp',
        '/var/www/html/16/dev/app/api/xml/sinfirma-comp',
    ];
    private const FUENTES_XML = [
        'https://ultra.sistema14.com/api/xml/firmados-comp/',
        'https://sistema14.com/dev/app/api/xml/firmados-comp/',
        'https://sistema14.com/app/api/xml/firmados-comp/',
        'https://old.sistema14.com/xml/firmados-comp/',
        'https://sistema14.com/api/xml/firmados-comp/',
        'https://sistema14.com/xml/firmados-comp/',
        'https://ultra.sistema14.com/api/xml/sinfirma-comp/',
        'https://sistema14.com/dev/app/api/xml/sinfirma-comp/',
        'https://sistema14.com/app/api/xml/sinfirma-comp/',
        'https://old.sistema14.com/xml/sinfirma-comp/',
        'https://sistema14.com/api/xml/sinfirma-comp/',
        'https://sistema14.com/xml/sinfirma-comp/',
        'https://ultra.sistema14.com/api/xml/firmados/',
        'https://sistema14.com/app/api/xml/firmados/',
        'https://old.sistema14.com/xml/firmados/',
        'https://sistema14.com/api/xml/firmados/',
        'https://sistema14.com/xml/firmados/',
    ];
    private const FUENTES_PDF = [
        'https://old.sistema14.com/gen_fact_complemento_pdf.php?id=',
    ];
    private const FUENTES_PDF_ARCHIVOS = [
        'https://ultra.sistema14.com/api/xml/pdf-comp/',
        'https://sistema14.com/app/api/xml/pdf-comp/',
        'https://ultra.sistema14.com/api/xml/pdf/',
        'https://sistema14.com/app/api/xml/pdf/',
        'https://old.sistema14.com/xml/pdf/',
        'https://sistema14.com/api/xml/pdf/',
        'https://sistema14.com/xml/pdf/',
    ];

    public function __construct(private PDO $conexion, private string $raizProyecto)
    {
    }

    /** @return array<string,mixed> */
    public function obtener(int $relacionId, int $empresaId): array
    {
        if ($relacionId <= 0 || $empresaId <= 0) {
            throw new RuntimeException('El complemento solicitado no es válido.');
        }
        $consulta = $this->conexion->prepare(
            'SELECT fc.id, fc.uuid, fc.xml_firmado, fc.token, fc.serie, fc.folio, fc.id_pago,
                    e.razon AS empresa_nombre
             FROM facturar_rel_complementos rc
             INNER JOIN facturas_complementos fc ON fc.id = rc.id_complemento
             INNER JOIN empresas e ON e.id = fc.razon
             WHERE rc.id = :relacion AND fc.razon = :empresa LIMIT 1'
        );
        $consulta->bindValue(':relacion', $relacionId, PDO::PARAM_INT);
        $consulta->bindValue(':empresa', $empresaId, PDO::PARAM_INT);
        $consulta->execute();
        $complemento = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!is_array($complemento) || !$this->uuidValido((string) ($complemento['uuid'] ?? ''))) {
            throw new RuntimeException('El complemento timbrado no existe o no pertenece a la empresa activa.');
        }
        $complemento['uuid'] = strtoupper(trim((string) $complemento['uuid']));
        return $complemento;
    }

    /** @param array<string,mixed> $complemento */
    public function obtenerXml(array $complemento): ?string
    {
        $id = (int) $complemento['id'];
        $uuid = (string) $complemento['uuid'];
        $xmlBase = trim((string) ($complemento['xml_firmado'] ?? ''));
        if ($xmlBase !== '' && $this->esXmlEsperado($xmlBase, $uuid)) {
            return $xmlBase;
        }

        $nombres = $this->nombresXml($complemento);
        $directorios = array_merge(
            self::DIRECTORIOS_XML_EXTERNOS,
            array_map(
                fn(string $directorio): string => $this->raizProyecto . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $directorio),
                ['api/xml/firmados-comp', 'api/xml/sinfirma-comp', 'xml/firmados-comp', 'xml/sinfirma-comp',
                    'xml/firmados', 'xml/timbrados', 'xml/complementos', 'xml']
            )
        );
        foreach ($directorios as $directorio) {
            foreach ($nombres as $nombre) {
                $ruta = $directorio . DIRECTORY_SEPARATOR . $nombre;
                if (is_file($ruta) && filesize($ruta) <= 2097152) {
                    $contenido = file_get_contents($ruta);
                    if (is_string($contenido) && $this->esXmlEsperado($contenido, $uuid)) {
                        return $contenido;
                    }
                }
            }
        }

        $limite = microtime(true) + 12;
        foreach ($nombres as $nombre) {
            foreach (self::FUENTES_XML as $base) {
                $restante = $limite - microtime(true);
                if ($restante < 1) return null;
                $contenido = $this->descargar($base . rawurlencode($nombre), 2097152, min(4, (int) ceil($restante)));
                if ($contenido !== null && $this->esXmlEsperado($contenido, $uuid)) {
                    return $contenido;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $complemento */
    public function obtenerPdf(array $complemento): ?string
    {
        $id = (int) $complemento['id'];
        $uuid = strtoupper((string) $complemento['uuid']);
        $nombres = [
            'factura_complemento-' . $uuid . '.pdf',
            'factura_complemento-' . strtolower($uuid) . '.pdf',
            'Complemento-' . $id . '.pdf',
            'complemento-' . $id . '.pdf',
            'PDF-complemento-' . $id . '.pdf',
            'Complemento-' . $uuid . '.pdf',
            'complemento-' . strtolower($uuid) . '.pdf',
            $uuid . '.pdf',
        ];
        foreach (['api/xml/pdf-comp', 'xml/pdf-comp', 'xml/pdf', 'pdf', 'xml/firmados'] as $directorio) {
            foreach ($nombres as $nombre) {
                $ruta = $this->raizProyecto . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $directorio) . DIRECTORY_SEPARATOR . $nombre;
                if (is_file($ruta) && filesize($ruta) <= 15728640) {
                    $contenido = file_get_contents($ruta);
                    if (is_string($contenido) && str_starts_with($contenido, '%PDF-')) {
                        return $contenido;
                    }
                }
            }
        }

        foreach (self::FUENTES_PDF as $base) {
            $contenido = $this->descargar($base . $id, 15728640, 10);
            if ($contenido !== null && str_starts_with($contenido, '%PDF-')) {
                return $contenido;
            }
        }
        $limite = microtime(true) + 12;
        foreach ($nombres as $nombre) {
            foreach (self::FUENTES_PDF_ARCHIVOS as $base) {
                $restante = $limite - microtime(true);
                if ($restante < 1) return null;
                $contenido = $this->descargar($base . rawurlencode($nombre), 15728640, min(4, (int) ceil($restante)));
                if ($contenido !== null && str_starts_with($contenido, '%PDF-')) {
                    return $contenido;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $complemento */
    public function nombreBase(array $complemento): string
    {
        $empresa = trim((string) ($complemento['empresa_nombre'] ?? 'Empresa'));
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $empresa);
        $empresaSegura = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', is_string($nombre) ? $nombre : $empresa), '-');
        $empresaSegura = substr($empresaSegura !== '' ? $empresaSegura : 'Empresa', 0, 80);
        return $empresaSegura . '-Complemento-' . strtoupper((string) $complemento['uuid']);
    }

    /** @param array<string,mixed> $complemento @return array<int,string> */
    private function nombresXml(array $complemento): array
    {
        $id = (int) $complemento['id'];
        $uuid = strtoupper((string) $complemento['uuid']);
        $token = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($complemento['token'] ?? '')) ?: '';
        $idPago = max(0, (int) ($complemento['id_pago'] ?? 0));
        $nombres = [
            'XML-complemento-' . $id . '-' . $uuid . '.xml',
            'XML-complemento-' . $id . '-' . strtolower($uuid) . '.xml',
            'XML-complemento-' . $id . '.xml',
            'complemento-' . $id . '.xml',
            'XML-factura-' . $id . '.xml',
            'XML-factura-' . $id,
            'factura-' . $id . '.xml',
            'XML-' . $id . '.xml',
            'XML-pago-' . $id . '.xml',
            'factura-' . $uuid . '.xml',
            'XML-factura-' . $uuid . '.xml',
            'complemento-' . $uuid . '.xml',
            'XML-complemento-' . $uuid . '.xml',
            $uuid . '.xml',
            strtolower($uuid) . '.xml',
        ];
        if ($token !== '') {
            $nombres[] = $token . '.xml';
            $nombres[] = 'complemento-' . $token . '.xml';
        }
        if ($idPago > 0) {
            $nombres[] = 'XML-complemento-' . $idPago . '.xml';
            $nombres[] = 'XML-factura-' . $idPago . '.xml';
            $nombres[] = 'XML-factura-' . $idPago;
        }
        return array_unique($nombres);
    }

    private function uuidValido(string $uuid): bool
    {
        return preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{4}){3}-[A-Fa-f0-9]{12}$/D', trim($uuid)) === 1;
    }

    private function esXmlEsperado(string $contenido, string $uuid): bool
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument();
        try {
            if (!$documento->loadXML($contenido, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                || !$documento->documentElement instanceof DOMElement
                || $documento->documentElement->localName !== 'Comprobante') {
                return false;
            }
            $xpath = new DOMXPath($documento);
            $uuidXml = (string) $xpath->evaluate('string(//*[local-name()="TimbreFiscalDigital"]/@UUID)');
            return strtoupper(trim($uuidXml)) === strtoupper(trim($uuid));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    private function descargar(string $url, int $maximoBytes, int $segundos): ?string
    {
        if (!function_exists('curl_init')) return null;
        $curl = curl_init($url);
        if ($curl === false) return null;
        $contenido = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(3, $segundos),
            CURLOPT_TIMEOUT => $segundos,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($transferencia, string $fragmento) use (&$contenido, $maximoBytes): int {
                if (strlen($contenido) + strlen($fragmento) > $maximoBytes) return 0;
                $contenido .= $fragmento;
                return strlen($fragmento);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            return $ok === true && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 ? $contenido : null;
        } finally {
            curl_close($curl);
        }
    }
}
