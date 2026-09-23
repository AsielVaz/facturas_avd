<?php

declare(strict_types=1);

final class FacturaDocumentoLocalizador
{
    private const FUENTES_XML = [
        'https://ultra.sistema14.com/api/xml/firmados/',
        'https://sistema14.com/app/api/xml/firmados/',
        'https://old.sistema14.com/xml/firmados/',
        'https://sistema14.com/api/xml/firmados/',
        // 'https://sistema14.com/xml/firmados/',
    ];
    private const TAMANO_MAXIMO_XML = 2097152;

    public function __construct(private readonly string $raizProyecto)
    {
    }

    /** @param array<string, mixed> $factura */
    public function obtenerXml(int $facturaId, array $factura): ?string
    {
        $contenidoBase = trim((string) ($factura['xml_firmado'] ?? ''));
        if ($contenidoBase !== '' && $this->esXmlFactura($contenidoBase, (string) ($factura['uuid'] ?? ''))) {
            return $contenidoBase;
        }

        $directorios = [
            $this->raizProyecto . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'firmados',
            $this->raizProyecto . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'timbrados',
            $this->raizProyecto . DIRECTORY_SEPARATOR . 'xml',
        ];
        foreach ($this->rutasCandidatas($directorios, $facturaId, $factura, 'xml') as $ruta) {
            $contenido = is_file($ruta) ? file_get_contents($ruta) : false;
            if (is_string($contenido) && $this->esXmlFactura($contenido, (string) ($factura['uuid'] ?? ''))) {
                return $contenido;
            }
        }
        return $this->obtenerXmlRemoto($facturaId, $factura);
    }

    /** @param array<string, mixed> $factura */
    private function obtenerXmlRemoto(int $facturaId, array $factura): ?string
    {
        if ($facturaId <= 0 || !function_exists('curl_init')) {
            return null;
        }

        $uuid = trim((string) ($factura['uuid'] ?? ''));
        if (!preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{4}){3}-[A-Fa-f0-9]{12}$/D', $uuid)) {
            return null;
        }

        $nombres = [
            'XML-factura-' . $facturaId . '.xml',
            'factura-' . $facturaId . '.xml',
            'factura-' . strtoupper($uuid) . '.xml',
            strtoupper($uuid) . '.xml',
            $this->nombreSeguro((string) ($factura['empresa_nombre'] ?? 'Empresa')) . '-' . strtoupper($uuid) . '.xml',
        ];
        $limite = microtime(true) + 15;
        foreach (array_unique($nombres) as $nombre) {
            foreach (self::FUENTES_XML as $base) {
                $tiempoRestante = $limite - microtime(true);
                if ($tiempoRestante < 1) {
                    return null;
                }
                $contenido = $this->descargarXml($base . rawurlencode($nombre), min(5, (int) ceil($tiempoRestante)));
                if ($contenido !== null && $this->esXmlFactura($contenido, $uuid)) {
                    return $contenido;
                }
            }
        }
        return null;
    }

    private function descargarXml(string $url, int $tiempoMaximo): ?string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        $contenido = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(3, $tiempoMaximo),
            CURLOPT_TIMEOUT => $tiempoMaximo,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml;q=0.9'],
            CURLOPT_WRITEFUNCTION => static function ($transferencia, string $fragmento) use (&$contenido): int {
                if (strlen($contenido) + strlen($fragmento) > self::TAMANO_MAXIMO_XML) {
                    return 0;
                }
                $contenido .= $fragmento;
                return strlen($fragmento);
            },
        ]);
        try {
            $correcto = curl_exec($curl);
            return $correcto === true && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200
                ? $contenido
                : null;
        } finally {
            curl_close($curl);
        }
    }

    /** @param array<string, mixed> $factura */
    public function obtenerPdf(int $facturaId, array $factura): ?string
    {
        $directorios = [
            $this->raizProyecto . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'pdf',
            $this->raizProyecto . DIRECTORY_SEPARATOR . 'pdf',
            $this->raizProyecto . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'firmados',
        ];
        foreach ($this->rutasCandidatas($directorios, $facturaId, $factura, 'pdf') as $ruta) {
            $contenido = is_file($ruta) ? file_get_contents($ruta) : false;
            if (is_string($contenido) && str_starts_with($contenido, '%PDF-')) {
                return $contenido;
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $directorios
     * @param array<string, mixed> $factura
     * @return array<int, string>
     */
    private function rutasCandidatas(array $directorios, int $facturaId, array $factura, string $extension): array
    {
        $uuid = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) ($factura['uuid'] ?? ''))) ?: '';
        $token = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) ($factura['token'] ?? ''))) ?: '';
        $serie = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) ($factura['serie'] ?? ''))) ?: '';
        $folio = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) ($factura['folio'] ?? ''))) ?: '';
        $empresa = $this->nombreSeguro((string) ($factura['empresa_nombre'] ?? 'Empresa'));
        $extensiones = array_unique([$extension, strtoupper($extension)]);
        $bases = [
            'XML-factura-' . $facturaId,
            'xml-factura-' . $facturaId,
            'XML-' . $facturaId,
            'factura-' . $facturaId,
            'Factura-' . $facturaId,
            (string) $facturaId,
        ];
        if ($uuid !== '') {
            foreach (array_unique([$uuid, strtoupper($uuid), strtolower($uuid)]) as $uuidVariante) {
                $bases[] = 'factura-' . $uuidVariante;
                $bases[] = 'Factura-' . $uuidVariante;
                $bases[] = 'XML-factura-' . $uuidVariante;
                $bases[] = 'xml-factura-' . $uuidVariante;
                $bases[] = 'CFDI-' . $uuidVariante;
                $bases[] = 'cfdi-' . $uuidVariante;
                $bases[] = $uuidVariante;
                $bases[] = $empresa . '-' . $uuidVariante;
            }
        }
        if ($token !== '') {
            $bases[] = $token;
            $bases[] = 'factura-' . $token;
        }
        if ($folio !== '') {
            $bases[] = ($serie !== '' ? $serie . '-' : '') . $folio;
        }

        $nombres = [];
        foreach (array_unique($bases) as $base) {
            foreach ($extensiones as $extensionVariante) {
                $nombres[] = $base . '.' . $extensionVariante;
            }
        }

        $rutas = [];
        foreach ($directorios as $directorio) {
            foreach ($nombres as $nombre) {
                $rutas[] = rtrim($directorio, '/\\') . DIRECTORY_SEPARATOR . $nombre;
            }
        }
        return $rutas;
    }

    private function esXmlFactura(string $contenido, string $uuidEsperado): bool
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument();
        try {
            $cargado = $documento->loadXML($contenido, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
        if (!$cargado || !$documento->documentElement instanceof DOMElement
            || $documento->documentElement->localName !== 'Comprobante') {
            return false;
        }

        $uuidEsperado = strtoupper(trim($uuidEsperado));
        if ($uuidEsperado === '') {
            return true;
        }
        $xpath = new DOMXPath($documento);
        $uuidXml = strtoupper(trim((string) $xpath->evaluate(
            'string(//*[local-name()="TimbreFiscalDigital"]/@UUID)'
        )));
        return $uuidXml === $uuidEsperado;
    }

    private function nombreSeguro(string $nombre): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($nombre));
        $ascii = is_string($ascii) ? $ascii : $nombre;
        $seguro = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-') ?: 'Empresa';
        return substr($seguro, 0, 80);
    }
}
