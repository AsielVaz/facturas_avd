<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Faltan las dependencias de PDF. Ejecuta composer install.');
}
require_once $autoload;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class FacturaPdfException extends RuntimeException
{
}

final class FacturaPdfDocumento extends FPDF
{
    public function Header(): void
    {
    }

    public function Footer(): void
    {
        $this->SetY(-1.0);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(160, 164, 172);
        $this->Cell(0, 0.4, FacturaPdfAdministrador::textoPdf('Este documento es una representación impresa de un CFDI'), 0, 0, 'C');
    }

    public function bloque(float $x, float $y, float $ancho, float $alto, int $r, int $g, int $b): void
    {
        $this->SetFillColor($r, $g, $b);
        $this->Rect($x, $y, $ancho, $alto, 'F');
    }

    public function tarjeta(float $x, float $y, float $ancho, float $alto): void
    {
        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(224, 228, 234);
        $this->Rect($x, $y, $ancho, $alto, 'FD');
    }
}

final class FacturaPdfAdministrador
{
    private const ANCHO_PAGINA = 21.59;
    private const MARGEN = 0.8;
    private const ANCHO_CONTENIDO = 19.99;

    public function __construct(
        private readonly PDO $conexion,
        private readonly string $directorioPdf,
        private readonly string $directorioTemporal
    ) {
    }

    /** @return array{contenido: string, archivo: string, ruta: string, timbrada: bool} */
    public function generar(int $facturaId, int $empresaId): array
    {
        if ($facturaId <= 0 || $empresaId <= 0) {
            throw new FacturaPdfException('La factura solicitada no es válida.');
        }
        $datos = $this->obtenerDatos($facturaId, $empresaId);
        $pdf = $this->crearDocumento($datos);
        $contenido = $pdf->Output('S');
        if (!is_string($contenido) || $contenido === '') {
            throw new FacturaPdfException('No fue posible serializar el PDF de la factura.');
        }

        $this->prepararDirectorio($this->directorioPdf);
        $identificador = $datos['timbrada'] ? (string) $datos['uuid'] : (string) $facturaId;
        $archivo = 'factura-' . preg_replace('/[^A-Za-z0-9-]/', '', $identificador) . '.pdf';
        $ruta = rtrim($this->directorioPdf, '/\\') . DIRECTORY_SEPARATOR . $archivo;
        $this->guardarAtomico($ruta, $contenido);

        return ['contenido' => $contenido, 'archivo' => $archivo, 'ruta' => $ruta, 'timbrada' => (bool) $datos['timbrada']];
    }

    /** Convierte UTF-8 al juego de caracteres de las fuentes estándar de FPDF. */
    public static function textoPdf(mixed $texto): string
    {
        $texto = (string) ($texto ?? '');
        if (!preg_match('//u', $texto)) {
            return $texto;
        }
        // Repara textos UTF-8 que previamente fueron interpretados y guardados como Windows-1252.
        if (str_contains($texto, 'Ã') || str_contains($texto, 'Â') || str_contains($texto, 'â')) {
            $reparado = iconv('UTF-8', 'Windows-1252//IGNORE', $texto);
            if ($reparado !== false && preg_match('//u', $reparado)) {
                $texto = $reparado;
            }
        }
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);
        return $convertido !== false ? $convertido : $texto;
    }

    /** @param array<string, mixed> $datos */
    private function crearDocumento(array $datos): FacturaPdfDocumento
    {
        $pdf = new FacturaPdfDocumento('P', 'cm', 'Letter');
        $pdf->SetMargins(self::MARGEN, self::MARGEN, self::MARGEN);
        $pdf->SetAutoPageBreak(true, 1.35);
        $pdf->SetTitle(self::textoPdf('Factura ' . $datos['serie'] . '-' . $datos['folio']));
        $pdf->SetAuthor(self::textoPdf((string) $datos['emisor_nombre']));
        $pdf->AddPage();

        $this->dibujarCabecera($pdf, $datos);
        $this->dibujarPartes($pdf, $datos);
        $y = $this->dibujarConceptos($pdf, $datos, 9.5);
        $y = $this->dibujarTotales($pdf, $datos, $y + 0.45);
        $y = $this->dibujarComplementos($pdf, $datos, $y + 0.45);
        $this->dibujarInformacionFiscal($pdf, $datos, $y + 0.35);

        return $pdf;
    }

    /** @param array<string, mixed> $datos */
    private function dibujarCabecera(FacturaPdfDocumento $pdf, array $datos): void
    {
        $pdf->bloque(0, 0, self::ANCHO_PAGINA, 4.0, 255, 255, 255);
        $logo = $this->resolverLogo();
        if ($logo !== null) {
            [$anchoOriginal, $altoOriginal] = getimagesize($logo) ?: [1, 1];
            $ratio = max(0.01, $anchoOriginal / max(1, $altoOriginal));
            $alto = min(2.5, 5.8 / $ratio);
            $ancho = min(5.8, $alto * $ratio);
            $pdf->Image($logo, self::MARGEN, 0.6, $ancho, $alto);
        } else {
            $pdf->bloque(self::MARGEN, 0.65, 2.4, 2.4, 31, 41, 55);
            $pdf->SetXY(self::MARGEN, 1.35);
            $pdf->SetFont('Arial', 'B', 20);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(2.4, 0.8, self::textoPdf($this->iniciales((string) $datos['emisor_nombre'])), 0, 0, 'C');
        }

        $columna = self::MARGEN + 6.3;
        $ancho = self::ANCHO_CONTENIDO - 6.3;
        $pdf->SetXY($columna, 0.45);
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->MultiCell($ancho, 0.58, self::textoPdf((string) $datos['emisor_nombre']), 0, 'R');
        $pdf->SetX($columna);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(92, 99, 112);
        $pdf->Cell($ancho, 0.38, (string) $datos['emisor_rfc'], 0, 2, 'R');
        $pdf->SetX($columna);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->Cell($ancho, 0.34, self::textoPdf('Régimen ' . $datos['emisor_regimen'] . ' - CP ' . $datos['lugar_expedicion']), 0, 2, 'R');

        $badgeX = self::ANCHO_PAGINA - self::MARGEN - 6.2;
        $pdf->bloque($badgeX, 2.5, 6.2, 1.12, 31, 41, 55);
        $pdf->SetXY($badgeX, 2.6);
        $pdf->SetFont('Arial', 'B', 10.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(6.2, 0.46, self::textoPdf('FACTURA #' . $datos['serie'] . '-' . $datos['folio']), 0, 2, 'C');
        $pdf->SetX($badgeX);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->SetTextColor(184, 191, 202);
        $pdf->Cell(6.2, 0.34, 'CFDI v4.0 | ' . self::textoPdf((string) $datos['tipo_nombre']), 0, 0, 'C');

        $pdf->bloque(0, 4.1, self::ANCHO_PAGINA, 0.72, 31, 41, 55);
        if ($datos['timbrada']) {
            $pdf->SetXY(self::MARGEN, 4.16);
            $pdf->SetFont('Arial', '', 6.2);
            $pdf->SetTextColor(164, 174, 190);
            $pdf->Cell(10.5, 0.24, 'FOLIO FISCAL', 0, 2, 'L');
            $pdf->SetX(self::MARGEN);
            $pdf->SetFont('Arial', 'B', 7.1);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(10.5, 0.28, (string) $datos['uuid'], 0, 0, 'L');
            $pdf->SetXY(self::MARGEN + 10.5, 4.16);
            $pdf->SetFont('Arial', '', 6.2);
            $pdf->SetTextColor(164, 174, 190);
            $pdf->Cell(self::ANCHO_CONTENIDO - 10.5, 0.24, 'CERT. SAT: ' . $datos['num_certificado_sat'], 0, 2, 'R');
            $pdf->SetX(self::MARGEN + 10.5);
            $pdf->SetFont('Arial', '', 6.8);
            $pdf->SetTextColor(219, 224, 232);
            $pdf->Cell(self::ANCHO_CONTENIDO - 10.5, 0.28, self::textoPdf($this->fechaAmigable((string) $datos['fecha_timbrado']) . ' ' . $datos['hora_timbrado']), 0, 0, 'R');
        } else {
            $pdf->SetXY(self::MARGEN, 4.25);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(255, 198, 94);
            $pdf->Cell(self::ANCHO_CONTENIDO, 0.32, 'PRUEBA LOCAL - XML SIN TIMBRAR - SIN VALIDEZ FISCAL', 0, 0, 'C');
        }
    }

    /** @param array<string, mixed> $datos */
    private function dibujarPartes(FacturaPdfDocumento $pdf, array $datos): void
    {
        $y = 5.0;
        $ancho = (self::ANCHO_CONTENIDO - 0.45) / 2;
        $this->dibujarTarjeta($pdf, self::MARGEN, $y, $ancho, 'EMISOR', (string) $datos['emisor_nombre'], (string) $datos['emisor_rfc'], 'Régimen fiscal: ' . $datos['emisor_regimen']);
        $this->dibujarTarjeta($pdf, self::MARGEN + $ancho + 0.45, $y, $ancho, 'RECEPTOR', (string) $datos['receptor_nombre'], (string) $datos['receptor_rfc'], 'Régimen ' . $datos['receptor_regimen'] . ' - CP ' . $datos['receptor_cp'] . "\nUso CFDI: [" . $datos['uso_clave'] . '] ' . $datos['uso_nombre']);

        $yPago = 8.45;
        $campos = [
            ['FORMA DE PAGO', $datos['forma_clave'] . ' - ' . $datos['forma_nombre']],
            ['MÉTODO DE PAGO', $datos['metodo_clave'] . ' - ' . $datos['metodo_nombre']],
            ['MONEDA', $datos['moneda_clave'] . ' - ' . $datos['moneda_nombre']],
            ['FECHA DE EMISIÓN', $this->fechaAmigable((string) $datos['fecha_emision'])],
        ];
        $anchoCampo = self::ANCHO_CONTENIDO / count($campos);
        foreach ($campos as $indice => [$etiqueta, $valor]) {
            $x = self::MARGEN + ($indice * $anchoCampo);
            $pdf->SetXY($x, $yPago);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->SetTextColor(148, 153, 163);
            $pdf->Cell($anchoCampo - 0.1, 0.26, self::textoPdf($etiqueta), 0, 2, 'L');
            $pdf->SetX($x);
            $pdf->SetFont('Arial', '', 7.4);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell($anchoCampo - 0.1, 0.34, self::textoPdf($this->recortar((string) $valor, 38)), 0, 0, 'L');
        }
    }

    private function dibujarTarjeta(FacturaPdfDocumento $pdf, float $x, float $y, float $ancho, string $titulo, string $nombre, string $rfc, string $detalle): void
    {
        $pdf->tarjeta($x, $y, $ancho, 3.1);
        $pdf->SetXY($x + 0.22, $y + 0.18);
        $pdf->SetFont('Arial', 'B', 6.5);
        $pdf->SetTextColor(147, 151, 160);
        $pdf->Cell($ancho - 0.44, 0.28, $titulo, 0, 2, 'L');
        $pdf->SetX($x + 0.22);
        $pdf->SetFont('Arial', 'B', 10.2);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->MultiCell($ancho - 0.44, 0.43, self::textoPdf($this->recortar($nombre, 76)), 0, 'L');
        $pdf->SetX($x + 0.22);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(72, 82, 96);
        $pdf->Cell($ancho - 0.44, 0.34, 'RFC: ' . $rfc, 0, 2, 'L');
        $pdf->SetX($x + 0.22);
        $pdf->SetFont('Arial', '', 6.8);
        $pdf->SetTextColor(105, 112, 124);
        $pdf->MultiCell($ancho - 0.44, 0.3, self::textoPdf($detalle), 0, 'L');
    }

    /** @param array<string, mixed> $datos */
    private function dibujarConceptos(FacturaPdfDocumento $pdf, array $datos, float $y): float
    {
        $anchos = [1.35, 1.35, 2.35, 8.3, 2.5, 4.14];
        $cabecera = function (float $posicionY) use ($pdf, $anchos): float {
            $pdf->bloque(0, $posicionY, self::ANCHO_PAGINA, 0.58, 31, 41, 55);
            $pdf->SetXY(self::MARGEN, $posicionY + 0.12);
            $pdf->SetFont('Arial', 'B', 6.8);
            $pdf->SetTextColor(255, 255, 255);
            foreach ([['CANT.', 'C'], ['UNIDAD', 'L'], ['PROD./SERV.', 'L'], ['DESCRIPCIÓN', 'L'], ['P. UNITARIO', 'R'], ['IMPORTE', 'R']] as $indice => [$texto, $alineacion]) {
                $pdf->Cell($anchos[$indice], 0.32, self::textoPdf($texto), 0, 0, $alineacion);
            }
            return $posicionY + 0.58;
        };
        $y = $cabecera($y);

        foreach ($datos['conceptos'] as $indice => $concepto) {
            $descripcion = self::textoPdf((string) $concepto['descripcion']);
            $pdf->SetFont('Arial', 'B', 8);
            $lineas = max(1, (int) ceil($pdf->GetStringWidth($descripcion) / ($anchos[3] - 0.15)));
            $alto = max(0.7, ($lineas * 0.34) + 0.26);
            if ($y + $alto > 25.0) {
                $pdf->AddPage();
                $pdf->SetXY(self::MARGEN, 0.75);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->SetTextColor(31, 41, 55);
                $pdf->Cell(self::ANCHO_CONTENIDO, 0.4, self::textoPdf('Factura ' . $datos['serie'] . '-' . $datos['folio'] . ' - continuación'), 0, 0, 'R');
                $y = $cabecera(1.35);
            }
            if ($indice % 2 === 1) {
                $pdf->bloque(0, $y, self::ANCHO_PAGINA, $alto, 248, 249, 251);
            }
            $pdf->SetDrawColor(228, 231, 236);
            $pdf->Line(self::MARGEN, $y + $alto, self::MARGEN + self::ANCHO_CONTENIDO, $y + $alto);
            $medio = $y + ($alto / 2) - 0.17;
            $x = self::MARGEN;
            $pdf->SetXY($x, $medio);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell($anchos[0], 0.34, $this->numero((float) $concepto['cantidad'], 3), 0, 0, 'C');
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(98, 105, 118);
            $pdf->Cell($anchos[1], 0.34, self::textoPdf((string) $concepto['clave_unidad']), 0, 0, 'L');
            $pdf->SetFont('Courier', '', 6.4);
            $pdf->Cell($anchos[2], 0.34, self::textoPdf((string) $concepto['clave_prod_serv']), 0, 0, 'L');
            $x = self::MARGEN + $anchos[0] + $anchos[1] + $anchos[2];
            $pdf->SetXY($x, $y + 0.13);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->MultiCell($anchos[3], 0.34, $descripcion, 0, 'L');
            $pdf->SetXY($x + $anchos[3], $medio);
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetTextColor(80, 88, 101);
            $pdf->Cell($anchos[4], 0.34, $this->moneda((float) $concepto['valor_unitario']), 0, 0, 'R');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell($anchos[5], 0.34, $this->moneda((float) $concepto['importe']), 0, 0, 'R');
            $y += $alto;
        }
        return $y;
    }

    /** @param array<string, mixed> $datos */
    private function dibujarTotales(FacturaPdfDocumento $pdf, array $datos, float $y): float
    {
        $filas = [
            ['Subtotal', $datos['subtotal']],
            ['Descuento', -$datos['descuento']],
            ['(+) IVA trasladado', $datos['iva']],
        ];
        if ((float) $datos['retencion_isr'] > 0) {
            $filas[] = ['(-) ISR retenido ' . $this->numero((float) $datos['retencion_isr_tasa'], 4) . '%', -$datos['retencion_isr']];
        }
        if ((float) $datos['retencion_iva'] > 0) {
            $filas[] = ['(-) IVA retenido ' . $this->numero((float) $datos['retencion_iva_tasa'], 4) . '%', -$datos['retencion_iva']];
        }
        $alto = 1.28 + (count($filas) * 0.43);
        $necesario = $alto + ($datos['timbrada'] ? 6.4 : 2.1);
        if ($y + $necesario > 26.0) {
            $pdf->AddPage();
            $y = 1.2;
        }
        $anchoCaja = 7.7;
        $xCaja = self::MARGEN + self::ANCHO_CONTENIDO - $anchoCaja;
        $pdf->tarjeta($xCaja, $y, $anchoCaja, $alto);
        $posY = $y + 0.18;
        foreach ($filas as [$etiqueta, $importe]) {
            $pdf->SetXY($xCaja + 0.25, $posY);
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(96, 103, 115);
            $pdf->Cell(4.45, 0.34, self::textoPdf($etiqueta), 0, 0, 'L');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell(2.75, 0.34, $this->moneda((float) $importe), 0, 0, 'R');
            $posY += 0.43;
        }
        $pdf->bloque($xCaja, $y + $alto - 0.76, $anchoCaja, 0.76, 31, 41, 55);
        $pdf->SetXY($xCaja + 0.25, $y + $alto - 0.59);
        $pdf->SetFont('Arial', 'B', 10.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(4.45, 0.42, 'TOTAL ' . $datos['moneda_clave'], 0, 0, 'L');
        $pdf->Cell(2.75, 0.42, $this->moneda((float) $datos['total']), 0, 0, 'R');

        $anchoLetra = self::ANCHO_CONTENIDO - $anchoCaja - 0.45;
        $pdf->SetXY(self::MARGEN, $y + 0.18);
        $pdf->SetFont('Arial', 'B', 6.8);
        $pdf->SetTextColor(110, 116, 128);
        $pdf->Cell($anchoLetra, 0.3, 'TOTAL CON LETRA', 0, 2, 'L');
        $pdf->SetX(self::MARGEN);
        $pdf->SetFont('Arial', 'I', 8.2);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->MultiCell($anchoLetra, 0.38, self::textoPdf($this->totalConLetra((float) $datos['total'], (string) $datos['moneda_clave'])), 0, 'L');
        return $y + $alto;
    }

    /** @param array<string, mixed> $datos */
    private function dibujarComplementos(FacturaPdfDocumento $pdf, array $datos, float $y): float
    {
        if ($datos['complementos'] === []) {
            return $y;
        }
        $alto = 0.82 + (count($datos['complementos']) * 0.48);
        if ($y + $alto > 25.5) {
            $pdf->AddPage();
            $y = 1.2;
        }
        $pdf->SetXY(self::MARGEN, $y);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(75, 83, 96);
        $pdf->Cell(self::ANCHO_CONTENIDO, 0.32, 'COMPLEMENTOS DE PAGO', 0, 2, 'L');
        $y += 0.32;
        $pdf->bloque(0, $y, self::ANCHO_PAGINA, 0.42, 31, 41, 55);
        $pdf->SetXY(self::MARGEN, $y + 0.08);
        $pdf->SetFont('Arial', 'B', 6.6);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(4, 0.28, 'PAGO', 0, 0, 'C');
        $pdf->Cell(7, 0.28, 'MONTO', 0, 0, 'C');
        $pdf->Cell(8.99, 0.28, 'FECHA', 0, 0, 'C');
        $y += 0.42;
        foreach ($datos['complementos'] as $indice => $complemento) {
            if ($indice % 2 === 1) {
                $pdf->bloque(0, $y, self::ANCHO_PAGINA, 0.48, 248, 249, 251);
            }
            $pdf->SetXY(self::MARGEN, $y + 0.08);
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetTextColor(55, 63, 76);
            $pdf->Cell(4, 0.3, 'Pago ' . $complemento['num_pago'], 0, 0, 'C');
            $pdf->Cell(7, 0.3, $this->moneda((float) $complemento['monto']), 0, 0, 'C');
            $pdf->Cell(8.99, 0.3, self::textoPdf($this->fechaAmigable((string) $complemento['fecha_pago'])), 0, 0, 'C');
            $y += 0.48;
        }
        return $y;
    }

    /** @param array<string, mixed> $datos */
    private function dibujarInformacionFiscal(FacturaPdfDocumento $pdf, array $datos, float $y): void
    {
        if (!$datos['timbrada']) {
            return;
        }
        if ($y + 5.8 > 26.0) {
            $pdf->AddPage();
            $y = 1.2;
        }
        $pdf->SetDrawColor(226, 229, 234);
        $pdf->Line(self::MARGEN, $y, self::MARGEN + self::ANCHO_CONTENIDO, $y);
        $y += 0.25;

        $qrRuta = $this->crearQr($datos);
        if ($qrRuta !== null) {
            $pdf->Image($qrRuta, self::MARGEN, $y, 3.0, 3.0, 'PNG');
        }
        $xSellos = self::MARGEN + 3.35;
        $anchoSellos = self::ANCHO_CONTENIDO - 3.35;
        $this->dibujarSello($pdf, $xSellos, $y, $anchoSellos, 'Sello Digital del CFDI', (string) $datos['sello_cfd']);
        $this->dibujarSello($pdf, $xSellos, $y + 1.32, $anchoSellos, 'Sello del SAT', (string) $datos['sello_sat']);

        $cadenaY = $y + 3.15;
        $pdf->SetXY(self::MARGEN, $cadenaY);
        $pdf->SetFont('Arial', 'B', 6.4);
        $pdf->SetTextColor(75, 83, 96);
        $pdf->Cell(self::ANCHO_CONTENIDO, 0.28, self::textoPdf('Cadena Original del complemento de certificación digital del SAT'), 0, 2, 'L');
        $pdf->SetX(self::MARGEN);
        $pdf->SetFont('Courier', '', 5.1);
        $pdf->SetTextColor(92, 98, 108);
        $cadena = (string) $datos['cadena_original'];
        $pdf->MultiCell(self::ANCHO_CONTENIDO, 0.2, self::textoPdf($cadena !== '' ? $cadena : 'Disponible en el XML oficial timbrado.'), 0, 'L');
        $pdf->SetXY(self::MARGEN, $cadenaY + 1.0);
        $pdf->SetFont('Arial', 'B', 6.5);
        $pdf->SetTextColor(75, 83, 96);
        $pdf->Cell(4.0, 0.3, 'No. Certificado SAT:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetTextColor(45, 52, 63);
        $pdf->Cell(5.5, 0.3, (string) $datos['num_certificado_sat'], 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 6.5);
        $pdf->Cell(4.3, 0.3, self::textoPdf('Fecha de certificación:'), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->Cell(6.19, 0.3, self::textoPdf($datos['fecha_timbrado'] . ' ' . $datos['hora_timbrado']), 0, 0, 'L');
    }

    private function dibujarSello(FacturaPdfDocumento $pdf, float $x, float $y, float $ancho, string $titulo, string $sello): void
    {
        $pdf->SetXY($x, $y);
        $pdf->SetFont('Arial', 'B', 6.5);
        $pdf->SetTextColor(75, 83, 96);
        $pdf->Cell($ancho, 0.26, self::textoPdf($titulo), 0, 2, 'L');
        $pdf->SetX($x);
        $pdf->SetFont('Courier', '', 5.0);
        $pdf->SetTextColor(92, 98, 108);
        $pdf->MultiCell($ancho, 0.19, self::textoPdf($sello), 0, 'L');
    }

    /** @return array<string, mixed> */
    private function obtenerDatos(int $facturaId, int $empresaId): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT f.*, e.razon AS emisor_nombre, e.rfc AS emisor_rfc, e.cp AS emisor_cp,
                    e.regimen AS emisor_regimen, e.logo AS emisor_logo,
                    cc.razon_social AS receptor_nombre, cc.rfc AS receptor_rfc,
                    cc.domicilio AS receptor_domicilio, cc.cp AS receptor_cp,
                    cc.regimen_fiscal AS receptor_regimen,
                    fp.clave AS forma_clave, fp.forma_pago AS forma_nombre,
                    mp.clave AS metodo_clave, mp.metodo_pago AS metodo_nombre,
                    cm.clave_moneda AS moneda_clave, cm.moneda AS moneda_nombre,
                    uso.cod_sat AS uso_clave, uso.uso_cfdi AS uso_nombre
             FROM facturas f
             INNER JOIN empresas e ON e.id = f.razon
             LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED)
             LEFT JOIN formas_pago fp ON fp.id = CAST(f.forma_pago AS UNSIGNED)
             LEFT JOIN metodos_pago mp ON mp.id = f.metodo_pago
             LEFT JOIN catalogo_monedas cm ON cm.id = f.moneda
             LEFT JOIN catalogo_usos_cfdi uso ON uso.id = f.uso_cfdi
             WHERE f.id = :factura AND f.razon = :empresa LIMIT 1"
        );
        $consulta->execute([':factura' => $facturaId, ':empresa' => $empresaId]);
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!is_array($fila)) {
            throw new FacturaPdfException('La factura no existe o no pertenece a la empresa activa.');
        }

        $detalle = $this->conexion->prepare(
            "SELECT df.cantidad, df.precio_unitario, df.precio_neto, df.precio_neto_iva,
                    COALESCE(NULLIF(TRIM(df.concepto), ''), c.concepto, 'Concepto') AS descripcion,
                    COALESCE(NULLIF(TRIM(c.clave_sat), ''), NULLIF(TRIM(c.clave_producto), ''), '') AS clave_prod_serv,
                    COALESCE(NULLIF(TRIM(c.clave_unidad_medida), ''), '') AS clave_unidad,
                    COALESCE(df.iva, c.impuesto, 0) AS tasa_iva
             FROM detalle_factura df
             LEFT JOIN conceptos c ON c.id = df.id_producto
             WHERE df.id_factura = :factura ORDER BY df.id"
        );
        $detalle->execute([':factura' => $facturaId]);
        $conceptos = [];
        foreach ($detalle->fetchAll(PDO::FETCH_ASSOC) as $concepto) {
            $conceptos[] = [
                'cantidad' => (float) $concepto['cantidad'],
                'valor_unitario' => (float) $concepto['precio_unitario'],
                'importe' => (float) $concepto['precio_neto'],
                'descripcion' => (string) $concepto['descripcion'],
                'clave_prod_serv' => (string) $concepto['clave_prod_serv'],
                'clave_unidad' => (string) $concepto['clave_unidad'],
                'tasa_iva' => (float) $concepto['tasa_iva'],
            ];
        }
        if ($conceptos === []) {
            throw new FacturaPdfException('La factura no contiene conceptos para generar el PDF.');
        }

        $subtotalCalculado = array_sum(array_column($conceptos, 'importe'));
        $ivaCalculado = 0.0;
        foreach ($conceptos as $concepto) {
            $ivaCalculado += (float) $concepto['importe'] * ((float) $concepto['tasa_iva'] / 100);
        }
        $tasaIsr = (float) ($fila['retencion_isr_tasa'] ?? 0);
        $tasaIva = (float) ($fila['retencion_iva_tasa'] ?? 0);
        $datos = [
            'id' => $facturaId,
            'serie' => trim((string) ($fila['serie'] ?? '')) ?: 'S',
            'folio' => (string) ($fila['folio'] ?: $facturaId),
            'fecha_emision' => (string) ($fila['fecha'] ?? ''),
            'tipo_nombre' => $this->tipoNombre((string) ($fila['tipo_comprobante'] ?? 'I')),
            'emisor_nombre' => trim((string) $fila['emisor_nombre']),
            'emisor_rfc' => strtoupper(trim((string) $fila['emisor_rfc'])),
            'emisor_regimen' => trim((string) $fila['emisor_regimen']),
            'emisor_logo' => trim((string) $fila['emisor_logo']),
            'lugar_expedicion' => trim((string) $fila['emisor_cp']),
            'receptor_nombre' => trim((string) $fila['receptor_nombre']) ?: 'Receptor sin razón social',
            'receptor_rfc' => strtoupper(trim((string) $fila['receptor_rfc'])),
            'receptor_domicilio' => trim((string) $fila['receptor_domicilio']),
            'receptor_cp' => trim((string) $fila['receptor_cp']),
            'receptor_regimen' => trim((string) $fila['receptor_regimen']),
            'forma_clave' => str_pad((string) ($fila['forma_clave'] ?? $fila['forma_pago'] ?? ''), 2, '0', STR_PAD_LEFT),
            'forma_nombre' => trim((string) ($fila['forma_nombre'] ?? '')),
            'metodo_clave' => trim((string) ($fila['metodo_clave'] ?? '')),
            'metodo_nombre' => trim((string) ($fila['metodo_nombre'] ?? '')),
            'moneda_clave' => trim((string) ($fila['moneda_clave'] ?? 'MXN')) ?: 'MXN',
            'moneda_nombre' => trim((string) ($fila['moneda_nombre'] ?? 'Peso mexicano')),
            'uso_clave' => trim((string) ($fila['uso_clave'] ?? '')),
            'uso_nombre' => trim((string) ($fila['uso_nombre'] ?? '')),
            'subtotal' => (float) ($fila['xml_subtotal'] ?? 0) ?: $subtotalCalculado,
            'descuento' => 0.0,
            'iva' => round($ivaCalculado, 2),
            'retencion_isr_tasa' => $tasaIsr,
            'retencion_iva_tasa' => $tasaIva,
            'retencion_isr' => round($subtotalCalculado * ($tasaIsr / 100), 2),
            'retencion_iva' => round($subtotalCalculado * ($tasaIva / 100), 2),
            'total' => (float) ($fila['xml_total'] ?? 0) ?: (float) ($fila['total_iva'] ?? $fila['total_factura'] ?? 0),
            'uuid' => strtoupper(trim((string) ($fila['uuid'] ?? ''))),
            'num_certificado' => trim((string) ($fila['num_certificado'] ?? '')),
            'num_certificado_sat' => trim((string) ($fila['num_certificado_sat'] ?? '')),
            'sello_cfd' => trim((string) ($fila['sello_cfd'] ?? '')),
            'sello_sat' => trim((string) ($fila['sello_sat'] ?? '')),
            'fecha_timbrado' => (string) ($fila['fecha_timbrado'] ?? ''),
            'hora_timbrado' => (string) ($fila['hora_timbrado'] ?? ''),
            'cadena_original' => trim((string) ($fila['cadena'] ?? '')),
            'conceptos' => $conceptos,
            'complementos' => $this->obtenerComplementos($facturaId),
            'timbrada' => trim((string) ($fila['uuid'] ?? '')) !== '',
        ];

        $xml = $this->obtenerXmlTimbrado($facturaId, (string) ($fila['xml_firmado'] ?? ''));
        if ($xml !== null) {
            $datos = $this->aplicarXml($datos, $xml);
        }
        return $datos;
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    private function aplicarXml(array $datos, string $xml): array
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument('1.0', 'UTF-8');
        try {
            if (!$documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || !$documento->documentElement instanceof DOMElement) {
                return $datos;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
        $raiz = $documento->documentElement;
        if ($raiz->localName !== 'Comprobante') {
            return $datos;
        }
        foreach (['Serie' => 'serie', 'Folio' => 'folio', 'Total' => 'total', 'SubTotal' => 'subtotal', 'Descuento' => 'descuento', 'Moneda' => 'moneda_clave', 'LugarExpedicion' => 'lugar_expedicion'] as $atributo => $campo) {
            if ($raiz->hasAttribute($atributo)) {
                $datos[$campo] = in_array($campo, ['total', 'subtotal', 'descuento'], true) ? (float) $raiz->getAttribute($atributo) : $raiz->getAttribute($atributo);
            }
        }
        $datos['num_certificado'] = $raiz->getAttribute('NoCertificado') ?: $datos['num_certificado'];

        $emisores = $documento->getElementsByTagNameNS('http://www.sat.gob.mx/cfd/4', 'Emisor');
        if ($emisores->item(0) instanceof DOMElement) {
            $emisor = $emisores->item(0);
            $datos['emisor_nombre'] = $emisor->getAttribute('Nombre') ?: $datos['emisor_nombre'];
            $datos['emisor_rfc'] = $emisor->getAttribute('Rfc') ?: $datos['emisor_rfc'];
            $datos['emisor_regimen'] = $emisor->getAttribute('RegimenFiscal') ?: $datos['emisor_regimen'];
        }
        $receptores = $documento->getElementsByTagNameNS('http://www.sat.gob.mx/cfd/4', 'Receptor');
        if ($receptores->item(0) instanceof DOMElement) {
            $receptor = $receptores->item(0);
            $datos['receptor_nombre'] = $receptor->getAttribute('Nombre') ?: $datos['receptor_nombre'];
            $datos['receptor_rfc'] = $receptor->getAttribute('Rfc') ?: $datos['receptor_rfc'];
            $datos['receptor_cp'] = $receptor->getAttribute('DomicilioFiscalReceptor') ?: $datos['receptor_cp'];
            $datos['receptor_regimen'] = $receptor->getAttribute('RegimenFiscalReceptor') ?: $datos['receptor_regimen'];
            $datos['uso_clave'] = $receptor->getAttribute('UsoCFDI') ?: $datos['uso_clave'];
        }
        $conceptos = [];
        foreach ($documento->getElementsByTagNameNS('http://www.sat.gob.mx/cfd/4', 'Concepto') as $nodo) {
            if (!$nodo instanceof DOMElement) {
                continue;
            }
            $conceptos[] = [
                'cantidad' => (float) $nodo->getAttribute('Cantidad'),
                'valor_unitario' => (float) $nodo->getAttribute('ValorUnitario'),
                'importe' => (float) $nodo->getAttribute('Importe'),
                'descripcion' => $nodo->getAttribute('Descripcion'),
                'clave_prod_serv' => $nodo->getAttribute('ClaveProdServ'),
                'clave_unidad' => $nodo->getAttribute('ClaveUnidad'),
                'tasa_iva' => 0.0,
            ];
        }
        if ($conceptos !== []) {
            $datos['conceptos'] = $conceptos;
        }
        $timbres = $documento->getElementsByTagNameNS('http://www.sat.gob.mx/TimbreFiscalDigital', 'TimbreFiscalDigital');
        if ($timbres->item(0) instanceof DOMElement) {
            $timbre = $timbres->item(0);
            $datos['uuid'] = strtoupper($timbre->getAttribute('UUID'));
            [$datos['fecha_timbrado'], $datos['hora_timbrado']] = array_pad(explode('T', $timbre->getAttribute('FechaTimbrado'), 2), 2, '');
            $datos['num_certificado_sat'] = $timbre->getAttribute('NoCertificadoSAT');
            $datos['sello_cfd'] = $timbre->getAttribute('SelloCFD');
            $datos['sello_sat'] = $timbre->getAttribute('SelloSAT');
            $rfcPac = $timbre->getAttribute('RfcProvCertif');
            if ($rfcPac !== '') {
                $datos['cadena_original'] = '||' . $timbre->getAttribute('Version') . '|' . $datos['uuid'] . '|'
                    . $timbre->getAttribute('FechaTimbrado') . '|' . $rfcPac . '|' . $datos['sello_cfd'] . '|'
                    . $datos['num_certificado_sat'] . '||';
            }
            $datos['timbrada'] = $datos['uuid'] !== '';
        }
        return $datos;
    }

    /** @return array<int, array<string, mixed>> */
    private function obtenerComplementos(int $facturaId): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT num_pago, monto, fecha_pago FROM facturar_rel_complementos WHERE id_factura = :factura ORDER BY num_pago, id'
        );
        $consulta->execute([':factura' => $facturaId]);
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    private function obtenerXmlTimbrado(int $facturaId, string $xmlBase): ?string
    {
        $ruta = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'firmados'
            . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
        $xml = is_file($ruta) ? file_get_contents($ruta) : $xmlBase;
        return is_string($xml) && trim($xml) !== '' ? $xml : null;
    }

    /** @param array<string, mixed> $datos */
    private function crearQr(array $datos): ?string
    {
        if ($datos['uuid'] === '' || $datos['sello_cfd'] === '') {
            return null;
        }
        $this->prepararDirectorio($this->directorioTemporal);
        $ruta = rtrim($this->directorioTemporal, '/\\') . DIRECTORY_SEPARATOR . 'qr-factura-' . $datos['id'] . '.png';
        $url = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?'
            . http_build_query([
                'id' => $datos['uuid'],
                're' => $datos['emisor_rfc'],
                'rr' => $datos['receptor_rfc'],
                'tt' => number_format((float) $datos['total'], 6, '.', ''),
                'fe' => substr((string) $datos['sello_cfd'], -8),
            ], '', '&', PHP_QUERY_RFC3986);
        $opciones = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'scale' => 6,
            'eccLevel' => EccLevel::H,
        ]);
        (new QRCode($opciones))->render($url, $ruta);
        return is_file($ruta) ? $ruta : null;
    }

    private function resolverLogo(): ?string
    {
        $ruta = str_replace('\\', '/', trim(SesionEmpresa::logoActual()));

        if (filter_var($ruta, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $ruta) === 1) {
            $logoRemoto = $this->descargarLogoSesion($ruta);
            if ($logoRemoto !== null) {
                return $logoRemoto;
            }
        }

        $candidatos = [
            dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $ruta), DIRECTORY_SEPARATOR),
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logo-sm.png',
        ];
        foreach ($candidatos as $candidato) {
            if (is_file($candidato) && @getimagesize($candidato) !== false) {
                return $candidato;
            }
        }
        return null;
    }

    private function descargarLogoSesion(string $url): ?string
    {
        $contexto = stream_context_create([
            'http' => [
                'timeout' => 5,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'FacturasAVD/1.0',
            ],
        ]);
        $contenido = @file_get_contents($url, false, $contexto, 0, 5 * 1024 * 1024);
        if ($contenido === false || $contenido === '') {
            return null;
        }

        $informacion = @getimagesizefromstring($contenido);
        $extension = match ($informacion[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            default => null,
        };
        if ($extension === null) {
            return null;
        }

        $this->prepararDirectorio($this->directorioTemporal);
        $rutaTemporal = rtrim($this->directorioTemporal, '/\\')
            . DIRECTORY_SEPARATOR . 'logo-sesion-' . hash('sha256', $url) . '.' . $extension;
        if (!is_file($rutaTemporal)) {
            $this->guardarAtomico($rutaTemporal, $contenido);
        }
        return $rutaTemporal;
    }

    private function totalConLetra(float $total, string $moneda): string
    {
        $entero = (int) floor(round($total, 2));
        $centavos = (int) round(($total - $entero) * 100);
        if ($centavos === 100) {
            $entero++;
            $centavos = 0;
        }
        $formateador = new NumberFormatter('es_MX', NumberFormatter::SPELLOUT);
        $texto = mb_strtoupper((string) $formateador->format($entero), 'UTF-8');
        $unidad = strtoupper($moneda) === 'USD' ? 'DÓLARES' : 'PESOS';
        return $texto . ' ' . $unidad . ' ' . str_pad((string) $centavos, 2, '0', STR_PAD_LEFT) . '/100 ' . (strtoupper($moneda) === 'USD' ? 'USD' : 'M.N.');
    }

    private function tipoNombre(string $clave): string
    {
        return ['I' => 'Ingreso', 'E' => 'Egreso', 'T' => 'Traslado', 'N' => 'Nómina', 'P' => 'Pago'][$clave] ?? $clave;
    }

    private function fechaAmigable(string $fecha): string
    {
        try {
            $valor = new DateTimeImmutable($fecha);
            $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            return $valor->format('d') . ' de ' . $meses[(int) $valor->format('n')] . ' de ' . $valor->format('Y');
        } catch (Throwable) {
            return $fecha;
        }
    }

    private function moneda(float $valor): string
    {
        $signo = $valor < 0 ? '-$' : '$';
        return $signo . number_format(abs($valor), 2, '.', ',');
    }

    private function numero(float $valor, int $decimales): string
    {
        return rtrim(rtrim(number_format($valor, $decimales, '.', ''), '0'), '.');
    }

    private function iniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $resultado = '';
        foreach (array_slice($partes, 0, 2) as $parte) {
            $resultado .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        }
        return $resultado ?: 'CF';
    }

    private function recortar(string $texto, int $longitud): string
    {
        return mb_strlen($texto, 'UTF-8') > $longitud ? mb_substr($texto, 0, $longitud - 1, 'UTF-8') . '…' : $texto;
    }

    private function prepararDirectorio(string $directorio): void
    {
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new FacturaPdfException('No fue posible crear el directorio necesario para el PDF.');
        }
        if (!is_writable($directorio)) {
            throw new FacturaPdfException('El directorio del PDF no tiene permisos de escritura.');
        }
    }

    private function guardarAtomico(string $ruta, string $contenido): void
    {
        $temporal = $ruta . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporal, $contenido, LOCK_EX) === false || !rename($temporal, $ruta)) {
                throw new FacturaPdfException('No fue posible guardar el PDF generado.');
            }
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }
}
