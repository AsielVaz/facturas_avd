<?php

declare(strict_types=1);

/** Determina el tratamiento del CFDI original; el navegador no decide este valor. */
final class ComplementoTratamientoFiscal
{
    /** @param array<int,array<string,mixed>> $facturas @return array<int,string> */
    public static function desdeFacturas(PDO $conexion, array $facturas): array
    {
        if ($facturas === []) return [];
        $ids = array_values(array_unique(array_map(static fn(array $fila): int => (int) $fila['id'], $facturas)));
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $consulta = $conexion->prepare(
            'SELECT df.id_factura, df.iva, c.aplica_iva
             FROM detalle_factura df LEFT JOIN conceptos c ON c.id = df.id_producto
             WHERE df.id_factura IN (' . $marcadores . ')'
        );
        $consulta->execute($ids);
        $detalles = [];
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $detalle) {
            $detalles[(int) $detalle['id_factura']][] = $detalle;
        }
        $resultado = [];
        foreach ($facturas as $factura) {
            $id = (int) $factura['id'];
            $factura['detalles'] = $detalles[$id] ?? [];
            $resultado[$id] = self::desdeFactura($conexion, $factura);
        }
        return $resultado;
    }

    public static function nombre(string $codigo): string
    {
        return match ($codigo) {
            '4' => 'Retención 4%',
            'T' => 'IVA 16%',
            'T0' => 'Tasa 0%',
            'SV' => 'Sin IVA',
            default => 'Tratamiento no identificado',
        };
    }

    /** @param array<string,mixed> $factura */
    public static function desdeFactura(PDO $conexion, array $factura): string
    {
        $xml = trim((string) ($factura['xml_firmado'] ?? ''));
        if ($xml !== '' && strlen($xml) <= 2097152) {
            $anterior = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            try {
                $valido = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($anterior);
            }
            if ($valido && $dom->documentElement?->localName === 'Comprobante') {
                $xpath = new DOMXPath($dom);
                if ($xpath->query('//*[local-name()="Retencion" and @Impuesto="002"]')?->length > 0) return '4';
                if ($xpath->query('//*[local-name()="Traslado" and @Impuesto="002" and @TasaOCuota="0.160000"]')?->length > 0) return 'T';
                if ($xpath->query('//*[local-name()="Traslado" and @Impuesto="002" and @TasaOCuota="0.000000"]')?->length > 0) return 'T0';
                return 'SV';
            }
        }

        if (strcasecmp(trim((string) ($factura['retencion_transporte'] ?? '')), 'Si') === 0) return '4';
        if (array_key_exists('detalles', $factura)) {
            $detalles = $factura['detalles'];
        } else {
            $detalle = $conexion->prepare(
                'SELECT df.iva, c.aplica_iva
                 FROM detalle_factura df
                 LEFT JOIN conceptos c ON c.id = df.id_producto
                 WHERE df.id_factura = :factura'
            );
            $detalle->execute([':factura' => (int) $factura['id']]);
            $detalles = $detalle->fetchAll(PDO::FETCH_ASSOC);
        }
        $tasaCero = false;
        $sinIva = false;
        foreach ($detalles as $concepto) {
            if ((int) ($concepto['iva'] ?? 0) === 16) return 'T';
            if ((int) ($concepto['iva'] ?? -1) === 0) {
                $aplica = mb_strtolower(trim((string) ($concepto['aplica_iva'] ?? '')), 'UTF-8');
                if (in_array($aplica, ['1', 'si', 'sí', 'true'], true)) $tasaCero = true;
                else $sinIva = true;
            }
        }
        if ($tasaCero) return 'T0';
        if ($sinIva) return 'SV';
        $total = $factura['xml_total'] ?? null;
        $subtotal = $factura['xml_subtotal'] ?? null;
        if ($total !== null && $subtotal !== null && (float) $total - (float) $subtotal > 0.005) return 'T';
        return 'SV';
    }
}
