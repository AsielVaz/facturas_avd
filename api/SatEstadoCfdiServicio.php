<?php

declare(strict_types=1);

final class SatEstadoCfdiException extends RuntimeException
{
}

final class SatEstadoCfdiServicio
{
    private const URL = 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc';
    private const SOAP_ACTION = 'http://tempuri.org/IConsultaCFDIService/Consulta';

    /**
     * @param array{uuid:string,emisor_rfc:string,receptor_rfc:string,total:float|int|string,sello_cfd?:string} $factura
     * @return array{codigo_estatus:string,es_cancelable:string,estado:string,estatus_cancelacion:string,validacion_efos:string,comprobado:bool,cancelada:bool}
     */
    public function consultar(array $factura): array
    {
        $expresion = $this->crearExpresion($factura);
        $xmlSolicitud = $this->crearSobreSoap($expresion);
        $curl = curl_init(self::URL);
        if ($curl === false) {
            throw new SatEstadoCfdiException('No fue posible inicializar la consulta al SAT.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlSolicitud,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ' . self::SOAP_ACTION,
                'Accept: text/xml',
            ],
        ]);

        $respuesta = curl_exec($curl);
        $errorCurl = curl_error($curl);
        $codigoHttp = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($respuesta) || $respuesta === '') {
            throw new SatEstadoCfdiException('El SAT no devolvió una respuesta' . ($errorCurl !== '' ? ': ' . $errorCurl : '.'));
        }
        if ($codigoHttp < 200 || $codigoHttp >= 300) {
            throw new SatEstadoCfdiException('El SAT respondió con HTTP ' . $codigoHttp . '.');
        }

        return $this->interpretarRespuesta($respuesta);
    }

    /** @param array<string, mixed> $factura */
    private function crearExpresion(array $factura): string
    {
        $uuid = strtoupper(trim((string) ($factura['uuid'] ?? '')));
        $emisor = strtoupper(trim((string) ($factura['emisor_rfc'] ?? '')));
        $receptor = strtoupper(trim((string) ($factura['receptor_rfc'] ?? '')));
        $total = number_format((float) ($factura['total'] ?? 0), 6, '.', '');
        $sello = trim((string) ($factura['sello_cfd'] ?? ''));
        $ultimosSello = $sello !== '' ? substr($sello, -8) : '';

        if (preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid) !== 1) {
            throw new SatEstadoCfdiException('El UUID de la factura no es válido.');
        }
        if ($emisor === '' || $receptor === '') {
            throw new SatEstadoCfdiException('La factura no tiene completos los RFC para consultar al SAT.');
        }

        return sprintf(
            '?id=%s&re=%s&rr=%s&tt=%s&fe=%s',
            $uuid,
            $emisor,
            $receptor,
            $total,
            $ultimosSello
        );
    }

    private function crearSobreSoap(string $expresion): string
    {
        $documento = new DOMDocument('1.0', 'utf-8');
        $sobre = $documento->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 's:Envelope');
        $documento->appendChild($sobre);
        $cuerpo = $documento->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 's:Body');
        $sobre->appendChild($cuerpo);
        $consulta = $documento->createElementNS('http://tempuri.org/', 'Consulta');
        $cuerpo->appendChild($consulta);
        $expresionNodo = $documento->createElement('expresionImpresa');
        $expresionNodo->appendChild($documento->createTextNode($expresion));
        $consulta->appendChild($expresionNodo);
        $xml = $documento->saveXML();
        if (!is_string($xml)) {
            throw new SatEstadoCfdiException('No fue posible construir la solicitud para el SAT.');
        }
        return $xml;
    }

    /**
     * @return array{codigo_estatus:string,es_cancelable:string,estado:string,estatus_cancelacion:string,validacion_efos:string,comprobado:bool,cancelada:bool}
     */
    private function interpretarRespuesta(string $xml): array
    {
        $documento = new DOMDocument();
        $erroresPrevios = libxml_use_internal_errors(true);
        $cargado = $documento->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($erroresPrevios);
        if (!$cargado) {
            throw new SatEstadoCfdiException('El SAT devolvió XML no válido.');
        }

        $xpath = new DOMXPath($documento);
        $fault = trim((string) $xpath->evaluate('string(//*[local-name()="Fault"]/*[local-name()="faultstring"])'));
        if ($fault !== '') {
            throw new SatEstadoCfdiException('El SAT rechazó la consulta: ' . $fault);
        }

        $resultado = [
            'codigo_estatus' => $this->valor($xpath, 'CodigoEstatus'),
            'es_cancelable' => $this->valor($xpath, 'EsCancelable'),
            'estado' => $this->valor($xpath, 'Estado'),
            'estatus_cancelacion' => $this->valor($xpath, 'EstatusCancelacion'),
            'validacion_efos' => $this->valor($xpath, 'ValidacionEFOS'),
        ];
        if ($resultado['codigo_estatus'] === '' && $resultado['estado'] === '') {
            throw new SatEstadoCfdiException('La respuesta del SAT no contiene el estado del CFDI.');
        }

        $estadoNormalizado = mb_strtolower($resultado['estado'], 'UTF-8');
        return $resultado + [
            'comprobado' => true,
            'cancelada' => str_contains($estadoNormalizado, 'cancelad'),
        ];
    }

    private function valor(DOMXPath $xpath, string $nombre): string
    {
        return trim((string) $xpath->evaluate('string(//*[local-name()="' . $nombre . '"])'));
    }
}
