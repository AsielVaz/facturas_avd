<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturaAdministrador.php';

final class FacturaVistaAdministrador
{
    public function __construct(private readonly FacturaAdministrador $facturas)
    {
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{filas: array<int, array<string, string>>, paginacion: array<string, int>, resumen: array<string, int|float>}
     */
    public function cargar(string $tipo, array $filtros): array
    {
        $resultado = $this->facturas->listar(
            $tipo,
            max(1, (int) ($filtros['pagina'] ?? 1)),
            10,
            trim((string) ($filtros['buscar'] ?? '')),
            trim((string) ($filtros['fecha'] ?? ''))
        );

        return [
            'filas' => array_map(fn(array $fila): array => $this->formatearFila($fila, $tipo), $resultado['registros']),
            'paginacion' => [
                'total' => $resultado['total'],
                'pagina' => $resultado['pagina'],
                'paginas' => $resultado['paginas'],
                'por_pagina' => $resultado['por_pagina'],
            ],
            'resumen' => $this->facturas->obtenerResumen($tipo),
        ];
    }

    /** @param array<string, mixed> $fila
     *  @return array<string, string>
     */
    private function formatearFila(array $fila, string $tipo): array
    {
        $cliente = trim((string) ($fila['cliente'] ?? 'Cliente sin nombre'), " \t\n\r\0\x0B\"");
        $statusError = trim((string) ($fila['status_error'] ?? ''));
        $esError = strtolower((string) ($fila['status'] ?? '')) === 'error' || $statusError !== '';
        $esCancelada = str_contains(strtolower((string) ($fila['status'] ?? '')), 'cancel');
        $fecha = !empty($fila['fecha']) ? new DateTimeImmutable((string) $fila['fecha']) : null;
        $fechaTimbrado = !empty($fila['fecha_timbrado']) ? new DateTimeImmutable((string) $fila['fecha_timbrado']) : null;
        $uuid = trim((string) ($fila['uuid'] ?? ''));
        $moneda = (string) ($fila['moneda'] ?? 'MXN');

        if ($tipo === 'timbradas') {
            $estado = $esCancelada ? 'Cancelada' : 'Vigente';
            $color = $esCancelada ? 'danger' : 'success';
            $detalle = ($fechaTimbrado?->format('d/m/Y') ?? 'Sin fecha') . (!empty($fila['hora_timbrado']) ? ' · ' . substr((string) $fila['hora_timbrado'], 0, 5) : '');
        } else {
            $estado = $esError ? 'Error' : 'Pendiente';
            $color = $esError ? 'danger' : 'warning';
            $detalle = $statusError !== '' ? mb_strimwidth($statusError, 0, 42, '…', 'UTF-8') : 'Lista para timbrar';
        }

        return [
            'id' => (string) $fila['id'],
            'folio' => trim((string) ($fila['serie'] ?? '') . '-' . (string) ($fila['folio'] ?? $fila['id'])),
            'serie' => 'Registro #' . (string) $fila['id'] . ' · ' . $moneda,
            'initials' => $this->obtenerIniciales($cliente),
            'client' => $cliente !== '' ? $cliente : 'Cliente sin nombre',
            'rfc' => trim((string) ($fila['rfc'] ?? '')) ?: 'RFC no disponible',
            'date' => $fecha?->format('d/m/Y') ?? 'Sin fecha',
            'due' => $detalle,
            'total' => ($moneda === 'USD' ? 'US$ ' : '$') . number_format((float) ($fila['total'] ?? 0), 2),
            'status' => $estado,
            'color' => $color,
            'uuid' => strlen($uuid) > 14 ? substr($uuid, 0, 8) . '…' . substr($uuid, -4) : $uuid,
        ];
    }

    private function obtenerIniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $iniciales = '';
        foreach (array_slice($partes, 0, 2) as $parte) {
            $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
        }
        return $iniciales !== '' ? $iniciales : 'CL';
    }
}
