<?php

declare(strict_types=1);

final class ComplementoMovimientoServicio
{
    public static function referenciaPositiva(mixed $valor): int
    {
        if (!is_int($valor) && !is_string($valor)) return 0;
        $texto = trim((string) $valor);
        if (!preg_match('/^[0-9]+$/D', $texto)) return 0;
        $numero = ltrim($texto, '0');
        if ($numero === '' || strlen($numero) > strlen((string) PHP_INT_MAX)
            || (strlen($numero) === strlen((string) PHP_INT_MAX) && strcmp($numero, (string) PHP_INT_MAX) > 0)) {
            return 0;
        }
        return (int) $numero;
    }

    /** @return array<string,mixed> */
    public function obtenerDisponible(PDO $conexion, int $id, int $empresa, int $usuario, bool $bloquear = false): array
    {
        if ($id <= 0) throw new ComplementoPagoValidacionException('El movimiento no es válido.');
        $consulta = $conexion->prepare(
            'SELECT p.id, p.nombre, p.empresa, p.id_captura, p.monto, p.tipo_movimiento,
                    p.status, p.banco, p.id_factura, p.id_factura_res, p.fecha_operacion
             FROM pagos p WHERE p.id = :id LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([':id' => $id]);
        $pago = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$pago || strtolower(trim((string) $pago['status'])) !== 'asignado') {
            throw new ComplementoPagoValidacionException('El movimiento no existe o no está asignado.');
        }
        $tipo = mb_strtolower(trim((string) $pago['tipo_movimiento']), 'UTF-8');
        if (str_contains($tipo, 'retiro') || str_contains($tipo, 'comisi')) {
            throw new ComplementoPagoValidacionException('El movimiento no puede utilizarse para un complemento.');
        }
        $banco = strtoupper(trim((string) $pago['banco']));
        if (in_array($banco, ['STP', '99'], true)) {
            throw new ComplementoPagoValidacionException('El banco del movimiento no permite generar este complemento.');
        }
        $facturaRelacionada = self::referenciaPositiva($pago['id_factura']);
        $complementoRelacionado = self::referenciaPositiva($pago['id_factura_res']);
        if (($facturaRelacionada > 0 && $facturaRelacionada !== 2) || $complementoRelacionado > 0) {
            throw new ComplementoPagoValidacionException('El movimiento ya tiene un documento relacionado.');
        }
        $referenciaComplemento = trim((string) ($pago['id_factura_res'] ?? ''));
        if ($referenciaComplemento !== '' && !preg_match('/^0+$/D', $referenciaComplemento)
            && $complementoRelacionado === 0) {
            throw new ComplementoPagoValidacionException('El movimiento contiene una referencia documental no válida.');
        }
        $cliente = self::referenciaPositiva($pago['nombre']);
        $empresaPago = self::referenciaPositiva($pago['empresa']);
        if (self::referenciaPositiva($pago['id_captura']) > 0) {
            $captura = $conexion->prepare('SELECT razon FROM captura WHERE id = :id LIMIT 1');
            $captura->execute([':id' => (int) $pago['id_captura']]);
            $razon = $captura->fetchColumn();
            if ($razon !== false) $empresaPago = self::referenciaPositiva($razon);
        }
        $asignacion = $conexion->prepare('SELECT cliente FROM usuarios WHERE id = :usuario LIMIT 1');
        $asignacion->execute([':usuario' => $usuario]);
        if ($cliente <= 0 || $empresaPago !== $empresa || $cliente !== (int) $asignacion->fetchColumn()) {
            throw new ComplementoPagoValidacionException('El movimiento no pertenece al cliente y empresa activos.');
        }
        $previos = $conexion->prepare('SELECT COUNT(*) FROM facturas_complementos WHERE id_pago = :id');
        $previos->execute([':id' => $id]);
        if ((int) $previos->fetchColumn() > 0 || (float) $pago['monto'] <= 0) {
            throw new ComplementoPagoValidacionException('El movimiento ya no tiene saldo disponible para un complemento.');
        }
        $pago['cliente_id'] = $cliente;
        $pago['empresa_id'] = $empresaPago;
        return $pago;
    }
}
