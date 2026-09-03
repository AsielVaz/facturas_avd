<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class FacturacionAdministrador
{
    private readonly int $empresa;

    public function __construct(private readonly PDO $conexion, ?int $empresa = null)
    {
        $this->empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
    }

    /** @return array<string, mixed> */
    public function obtenerContexto(): array
    {
        return [
            'emisor' => $this->obtenerEmisor(),
            'clientes' => $this->listarClientes(),
            'conceptos' => $this->listarConceptos(),
            'catalogos' => $this->obtenerCatalogos(),
            'solo_lectura' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function obtenerEmisor(): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT id, TRIM(COALESCE(razon, '')) AS nombre, UPPER(TRIM(rfc)) AS rfc,
                    TRIM(cp) AS cp, TRIM(COALESCE(regimen, '')) AS regimen_descripcion,
                    TRIM(COALESCE(calle, '')) AS calle, TRIM(COALESCE(numero, '')) AS numero,
                    TRIM(COALESCE(colonia, '')) AS colonia, TRIM(COALESCE(estado, '')) AS estado
             FROM empresas
             WHERE id = :empresa
             LIMIT 1"
        );
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->execute();
        $emisor = $consulta->fetch();
        if (!$emisor) {
            throw new RuntimeException('La empresa activa no existe.');
        }

        preg_match('/^(\d{3})/', (string) $emisor['regimen_descripcion'], $coincidencia);
        $emisor['regimen_fiscal'] = $coincidencia[1] ?? '';
        $errores = [];
        if ((string) $emisor['nombre'] === '') {
            $errores[] = 'La empresa no tiene razón social.';
        }
        if (!$this->rfcValido((string) $emisor['rfc'])) {
            $errores[] = 'El RFC del emisor no tiene un formato válido.';
        }
        if (!$this->cpValido((string) $emisor['cp'])) {
            $errores[] = 'El código postal del emisor debe tener cinco dígitos.';
        }
        if (!preg_match('/^\d{3}$/', (string) $emisor['regimen_fiscal'])) {
            $errores[] = 'No fue posible obtener la clave del régimen fiscal del emisor.';
        }
        $emisor['completo'] = $errores === [];
        $emisor['errores'] = $errores;
        return $emisor;
    }

    /** @return array<int, array<string, mixed>> */
    public function listarClientes(string $buscar = ''): array
    {
        $buscar = trim($buscar);
        $sql = "SELECT
                    c.id,
                    TRIM(BOTH '\"' FROM TRIM(COALESCE(NULLIF(c.nombre, ''), NULLIF(c.razon_social, ''), CONCAT('Cliente #', c.id)))) AS nombre,
                    COALESCE(c.activo, 1) AS activo,
                    COUNT(cc.id) AS perfiles,
                    SUM(
                        TRIM(COALESCE(cc.razon_social, '')) <> ''
                        AND UPPER(TRIM(COALESCE(cc.rfc, ''))) REGEXP '^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$'
                        AND TRIM(COALESCE(cc.cp, '')) REGEXP '^[0-9]{5}$'
                        AND cc.regimen_fiscal BETWEEN 600 AND 999
                    ) AS perfiles_completos
                FROM clientes c
                LEFT JOIN claves_cortas cc ON cc.id_cliente = c.id
                WHERE (:buscar_vacio = '' OR c.nombre LIKE :buscar_nombre OR c.razon_social LIKE :buscar_razon)
                GROUP BY c.id, c.nombre, c.razon_social, c.activo
                ORDER BY (COALESCE(c.activo, 1) = 1) DESC, nombre, c.id";
        $consulta = $this->conexion->prepare($sql);
        $consulta->bindValue(':buscar_vacio', $buscar, PDO::PARAM_STR);
        $consulta->bindValue(':buscar_nombre', '%' . $buscar . '%', PDO::PARAM_STR);
        $consulta->bindValue(':buscar_razon', '%' . $buscar . '%', PDO::PARAM_STR);
        $consulta->execute();
        $clientes = $consulta->fetchAll();
        foreach ($clientes as &$cliente) {
            $cliente['id'] = (int) $cliente['id'];
            $cliente['activo'] = (int) $cliente['activo'] === 1;
            $cliente['perfiles'] = (int) $cliente['perfiles'];
            $cliente['perfiles_completos'] = (int) $cliente['perfiles_completos'];
            $cliente['facturable'] = $cliente['perfiles_completos'] > 0;
        }
        unset($cliente);
        return $clientes;
    }

    /** @return array{cliente: array<string, mixed>, perfiles: array<int, array<string, mixed>>} */
    public function listarPerfilesCliente(int $clienteId): array
    {
        $consultaCliente = $this->conexion->prepare(
            "SELECT id, TRIM(BOTH '\"' FROM TRIM(COALESCE(NULLIF(nombre, ''), NULLIF(razon_social, ''), CONCAT('Cliente #', id)))) AS nombre
             FROM clientes WHERE id = :cliente LIMIT 1"
        );
        $consultaCliente->bindValue(':cliente', $clienteId, PDO::PARAM_INT);
        $consultaCliente->execute();
        $cliente = $consultaCliente->fetch();
        if (!$cliente) {
            throw new RuntimeException('El cliente seleccionado no existe.');
        }

        $consulta = $this->conexion->prepare(
            "SELECT id, id_cliente, TRIM(clave_corta) AS clave_corta,
                    TRIM(razon_social) AS razon_social, UPPER(TRIM(rfc)) AS rfc,
                    TRIM(COALESCE(cp, '')) AS cp, COALESCE(regimen_fiscal, 0) AS regimen_fiscal,
                    TRIM(COALESCE(domicilio, '')) AS domicilio
             FROM claves_cortas
             WHERE id_cliente = :cliente
             ORDER BY razon_social, clave_corta, id"
        );
        $consulta->bindValue(':cliente', $clienteId, PDO::PARAM_INT);
        $consulta->execute();
        $perfiles = $consulta->fetchAll();
        foreach ($perfiles as &$perfil) {
            $perfil['id'] = (int) $perfil['id'];
            $perfil['id_cliente'] = (int) $perfil['id_cliente'];
            $perfil['regimen_fiscal'] = (string) ((int) $perfil['regimen_fiscal'] ?: '');
            $perfil['errores'] = $this->validarPerfil($perfil);
            $perfil['completo'] = $perfil['errores'] === [];
            $perfil['tipo_persona'] = strlen((string) $perfil['rfc']) === 13 ? 'fisica' : 'moral';
        }
        unset($perfil);
        $cliente['id'] = (int) $cliente['id'];
        return ['cliente' => $cliente, 'perfiles' => $perfiles];
    }

    /** @return array<int, array<string, mixed>> */
    public function listarConceptos(string $buscar = ''): array
    {
        $buscar = trim($buscar);
        $consulta = $this->conexion->prepare(
            "SELECT id, TRIM(COALESCE(concepto, '')) AS concepto, TRIM(clave_sat) AS clave_sat,
                    TRIM(COALESCE(clave_producto, '')) AS clave_producto,
                    UPPER(TRIM(clave_unidad_medida)) AS clave_unidad_medida,
                    precio_min, precio_max, unidades_max, aplica_iva,
                    COALESCE(objeto_impuesto, 0) AS objeto_impuesto_local,
                    COALESCE(impuesto, 0) AS tasa_iva
             FROM conceptos
             WHERE CAST(razon AS UNSIGNED) = :empresa
               AND (:buscar_vacio = '' OR concepto LIKE :buscar_concepto OR clave_sat LIKE :buscar_clave)
             ORDER BY concepto, id"
        );
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->bindValue(':buscar_vacio', $buscar, PDO::PARAM_STR);
        $consulta->bindValue(':buscar_concepto', '%' . $buscar . '%', PDO::PARAM_STR);
        $consulta->bindValue(':buscar_clave', '%' . $buscar . '%', PDO::PARAM_STR);
        $consulta->execute();
        $conceptos = $consulta->fetchAll();
        foreach ($conceptos as &$concepto) {
            $concepto['id'] = (int) $concepto['id'];
            $concepto['precio_min'] = (float) $concepto['precio_min'];
            $concepto['precio_max'] = (float) $concepto['precio_max'];
            $concepto['unidades_max'] = $concepto['unidades_max'] !== null ? (float) $concepto['unidades_max'] : null;
            $concepto['tasa_iva'] = (float) $concepto['tasa_iva'];
            $aplicaIva = in_array(strtolower(trim((string) $concepto['aplica_iva'])), ['1', 'si', 'sí', 'true'], true);
            $concepto['objeto_impuesto'] = (int) $concepto['objeto_impuesto_local'] === 0
                ? '01'
                : ($aplicaIva ? '02' : '04');
            $errores = [];
            if ((string) $concepto['concepto'] === '') {
                $errores[] = 'Descripción vacía.';
            }
            if (!preg_match('/^\d{8}$/', (string) $concepto['clave_sat'])) {
                $errores[] = 'Clave SAT inválida.';
            }
            if (!preg_match('/^[A-Z0-9]{2,3}$/', (string) $concepto['clave_unidad_medida'])) {
                $errores[] = 'Clave de unidad inválida.';
            }
            if ($concepto['objeto_impuesto'] === '02' && !in_array($concepto['tasa_iva'], [0.0, 8.0, 16.0], true)) {
                $errores[] = 'La tasa local de IVA no es 0%, 8% o 16%.';
            }
            $concepto['errores'] = $errores;
            $concepto['disponible'] = $errores === [];
            unset($concepto['objeto_impuesto_local']);
        }
        unset($concepto);
        return $conceptos;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function obtenerCatalogos(): array
    {
        $metodos = $this->conexion->query(
            "SELECT id, UPPER(TRIM(clave)) AS clave, TRIM(metodo_pago) AS descripcion
             FROM metodos_pago WHERE clave IN ('PUE', 'PPD') ORDER BY id"
        )->fetchAll();
        $monedas = $this->conexion->query(
            "SELECT id, UPPER(TRIM(clave_moneda)) AS clave, TRIM(moneda) AS descripcion
             FROM catalogo_monedas ORDER BY id"
        )->fetchAll();
        $formas = $this->conexion->query(
            "SELECT fp.id, LPAD(fp.clave, 2, '0') AS clave, TRIM(fp.forma_pago) AS descripcion
             FROM formas_pago fp
             INNER JOIN (
                 SELECT clave, MIN(id) AS id
                 FROM formas_pago
                 WHERE LOWER(forma_pago) NOT LIKE '%prueba%'
                 GROUP BY clave
             ) validas ON validas.id = fp.id
             ORDER BY fp.clave"
        )->fetchAll();

        foreach ([$metodos, $monedas, $formas] as &$catalogo) {
            foreach ($catalogo as &$fila) {
                $fila['id'] = (int) $fila['id'];
                $fila['descripcion'] = trim((string) $fila['descripcion']);
            }
            unset($fila);
        }
        unset($catalogo);

        return [
            'metodos_pago' => $metodos,
            'monedas' => $monedas,
            'formas_pago' => $formas,
            'usos_cfdi' => array_values($this->usosCfdi()),
            'exportaciones' => [
                ['clave' => '01', 'descripcion' => 'No aplica'],
                ['clave' => '02', 'descripcion' => 'Definitiva'],
                ['clave' => '03', 'descripcion' => 'Temporal'],
                ['clave' => '04', 'descripcion' => 'Definitiva con clave distinta de A1 o complemento'],
            ],
        ];
    }

    /**
     * Valida y calcula una factura sin crear ni actualizar registros.
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    public function validarBorrador(array $datos, array $conceptosAdicionales = []): array
    {
        $errores = [];
        $advertencias = [
            'Esta vista previa no fue guardada, sellada ni timbrada.',
            'La validación definitiva de catálogos, RFC y reglas fiscales corresponde al PAC antes del timbrado.',
        ];
        $emisor = $this->obtenerEmisor();
        array_push($errores, ...$emisor['errores']);

        $clienteId = max(0, (int) ($datos['cliente_id'] ?? 0));
        $perfilId = max(0, (int) ($datos['perfil_id'] ?? 0));
        try {
            $perfilesCliente = $this->listarPerfilesCliente($clienteId);
        } catch (RuntimeException $error) {
            $errores[] = $error->getMessage();
            $perfilesCliente = ['cliente' => ['id' => 0, 'nombre' => ''], 'perfiles' => []];
        }
        $perfil = null;
        foreach ($perfilesCliente['perfiles'] as $posiblePerfil) {
            if ((int) $posiblePerfil['id'] === $perfilId) {
                $perfil = $posiblePerfil;
                break;
            }
        }
        if ($perfil === null) {
            $errores[] = 'El perfil fiscal no pertenece al cliente seleccionado.';
        } else {
            array_push($errores, ...$perfil['errores']);
        }

        $catalogos = $this->obtenerCatalogos();
        $moneda = $this->buscarCatalogoPorId($catalogos['monedas'], (int) ($datos['moneda_id'] ?? 0));
        $metodo = $this->buscarCatalogoPorId($catalogos['metodos_pago'], (int) ($datos['metodo_pago_id'] ?? 0));
        $forma = $this->buscarCatalogoPorId($catalogos['formas_pago'], (int) ($datos['forma_pago_id'] ?? 0));
        if ($moneda === null) {
            $errores[] = 'Selecciona una moneda válida.';
        }
        if ($metodo === null) {
            $errores[] = 'Selecciona un método de pago válido.';
        }
        if ($forma === null) {
            $errores[] = 'Selecciona una forma de pago válida.';
        }
        if (($metodo['clave'] ?? '') === 'PPD' && ($forma['clave'] ?? '') !== '99') {
            $errores[] = 'Cuando el método es PPD la forma de pago debe ser 99 - Por definir.';
        }
        if (($metodo['clave'] ?? '') === 'PUE' && in_array(($forma['clave'] ?? ''), ['30', '99'], true)) {
            $errores[] = 'Para un CFDI de ingreso PUE la forma de pago no puede ser 30 ni 99.';
        }

        $tipoCambio = (float) ($datos['tipo_cambio'] ?? 1);
        if (($moneda['clave'] ?? '') === 'MXN') {
            $tipoCambio = 1.0;
        } elseif ($tipoCambio <= 0) {
            $errores[] = 'Indica un tipo de cambio mayor que cero para moneda extranjera.';
        }

        $usoClave = strtoupper(trim((string) ($datos['uso_cfdi'] ?? '')));
        $usos = $this->usosCfdi();
        $uso = $usos[$usoClave] ?? null;
        if ($uso === null) {
            $errores[] = 'Selecciona un uso CFDI válido.';
        } elseif ($perfil !== null) {
            $tipoPersona = (string) $perfil['tipo_persona'];
            if (empty($uso[$tipoPersona])) {
                $errores[] = "El uso CFDI {$usoClave} no corresponde al tipo de persona del receptor.";
            }
            if ($usoClave === 'CN01' && (string) $perfil['regimen_fiscal'] !== '605') {
                $errores[] = 'CN01 - Nómina requiere el régimen fiscal 605.';
            }
            if (in_array((string) $perfil['rfc'], ['XAXX010101000', 'XEXX010101000'], true)) {
                if ((string) $perfil['regimen_fiscal'] !== '616' || $usoClave !== 'S01') {
                    $errores[] = 'El RFC genérico requiere régimen 616 y uso CFDI S01.';
                }
                if ((string) $perfil['cp'] !== (string) $emisor['cp']) {
                    $errores[] = 'Para RFC genérico, el código postal del receptor debe coincidir con el lugar de expedición.';
                }
            }
        }

        $exportacion = (string) ($datos['exportacion'] ?? '01');
        if (!in_array($exportacion, ['01', '02', '03', '04'], true)) {
            $errores[] = 'Selecciona una clave de exportación válida.';
        }
        if ($exportacion !== '01') {
            $advertencias[] = 'Una operación de exportación puede requerir información y complementos que este preparador no captura todavía.';
        }

        $fecha = trim((string) ($datos['fecha'] ?? ''));
        if ($fecha === '') {
            $fecha = (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
            $errores[] = 'Selecciona una fecha válida para el comprobante.';
        }

        $partidasEntrada = is_array($datos['partidas'] ?? null) ? $datos['partidas'] : [];
        if ($partidasEntrada === []) {
            $errores[] = 'Agrega al menos un concepto.';
        }
        if (count($partidasEntrada) > 100) {
            $errores[] = 'La vista previa admite como máximo 100 conceptos.';
            $partidasEntrada = array_slice($partidasEntrada, 0, 100);
        }
        $conceptos = $this->obtenerConceptosPorIds(array_map(
            static fn(mixed $partida): int => (int) (is_array($partida) ? ($partida['concepto_id'] ?? 0) : 0),
            $partidasEntrada
        ));
        foreach ($conceptosAdicionales as $conceptoAdicional) {
            if (is_array($conceptoAdicional) && !empty($conceptoAdicional['id'])) {
                $conceptos[(int) $conceptoAdicional['id']] = $conceptoAdicional;
            }
        }

        $partidas = [];
        $subtotal = 0.0;
        $descuentoTotal = 0.0;
        $ivaTotal = 0.0;
        foreach ($partidasEntrada as $indice => $partidaEntrada) {
            if (!is_array($partidaEntrada)) {
                $errores[] = 'Una de las partidas tiene un formato inválido.';
                continue;
            }
            $numero = $indice + 1;
            $conceptoId = (int) ($partidaEntrada['concepto_id'] ?? 0);
            $concepto = $conceptos[$conceptoId] ?? null;
            if ($concepto === null) {
                $errores[] = "Partida {$numero}: el concepto no pertenece a la empresa activa.";
                continue;
            }
            array_push($errores, ...array_map(
                static fn(string $error): string => "Partida {$numero}: {$error}",
                $concepto['errores']
            ));
            $cantidad = (float) ($partidaEntrada['cantidad'] ?? 0);
            $precio = (float) ($partidaEntrada['precio_unitario'] ?? 0);
            $descuento = (float) ($partidaEntrada['descuento'] ?? 0);
            $descripcion = trim((string) ($partidaEntrada['descripcion'] ?? $concepto['concepto']));
            if ($descripcion === '') {
                $errores[] = "Partida {$numero}: la descripción no puede estar vacía.";
                $descripcion = (string) $concepto['concepto'];
            }
            if (mb_strlen($descripcion, 'UTF-8') > 1000) {
                $errores[] = "Partida {$numero}: la descripción no puede exceder 1000 caracteres.";
            }
            if ($cantidad <= 0) {
                $errores[] = "Partida {$numero}: la cantidad debe ser mayor que cero.";
            }
            if ($concepto['unidades_max'] !== null && $cantidad > (float) $concepto['unidades_max']) {
                $errores[] = "Partida {$numero}: la cantidad excede el máximo de {$concepto['unidades_max']}.";
            }
            if ($precio < 0) {
                $errores[] = "Partida {$numero}: el precio unitario no puede ser negativo.";
            }
            $importe = round($cantidad * $precio, 6);
            if ($descuento < 0 || $descuento > $importe) {
                $errores[] = "Partida {$numero}: el descuento no puede ser negativo ni mayor al importe.";
                $descuento = max(0, min($importe, $descuento));
            }
            $base = round($importe - $descuento, 6);
            $iva = $concepto['objeto_impuesto'] === '02'
                ? round($base * ((float) $concepto['tasa_iva'] / 100), 2)
                : 0.0;
            $totalPartida = round($base + $iva, 2);
            $subtotal += $importe;
            $descuentoTotal += $descuento;
            $ivaTotal += $iva;
            $partidas[] = [
                'numero' => $numero,
                'detalle_id' => max(0, (int) ($partidaEntrada['detalle_id'] ?? 0)),
                'concepto_id' => $conceptoId,
                'clave_prod_serv' => $concepto['clave_sat'],
                'no_identificacion' => $concepto['clave_producto'],
                'clave_unidad' => $concepto['clave_unidad_medida'],
                'descripcion' => $descripcion,
                'cantidad' => $cantidad,
                'valor_unitario' => round($precio, 6),
                'importe' => round($importe, 2),
                'descuento' => round($descuento, 2),
                'objeto_impuesto' => $concepto['objeto_impuesto'],
                'tasa_iva' => (float) $concepto['tasa_iva'],
                'iva' => $iva,
                'total' => $totalPartida,
            ];
        }

        $subtotal = round($subtotal, 2);
        $descuentoTotal = round($descuentoTotal, 2);
        $ivaTotal = round($ivaTotal, 2);
        $total = round($subtotal - $descuentoTotal + $ivaTotal, 2);
        if ($total <= 0 && $partidas !== []) {
            $errores[] = 'El total del comprobante debe ser mayor que cero.';
        }

        return [
            'valido' => $errores === [],
            'errores' => array_values(array_unique($errores)),
            'advertencias' => array_values(array_unique($advertencias)),
            'comprobante' => [
                'version' => '4.0',
                'fecha' => ($fechaValida ?: new DateTimeImmutable('today'))->format('Y-m-d') . 'T' . (new DateTimeImmutable('now'))->format('H:i:s'),
                'tipo_comprobante' => 'I',
                'exportacion' => $exportacion,
                'lugar_expedicion' => $emisor['cp'],
                'moneda' => $moneda['clave'] ?? '',
                'tipo_cambio' => round($tipoCambio, 6),
                'metodo_pago' => $metodo['clave'] ?? '',
                'forma_pago' => $forma['clave'] ?? '',
                'uso_cfdi' => $usoClave,
                'subtotal' => $subtotal,
                'descuento' => $descuentoTotal,
                'iva' => $ivaTotal,
                'total' => $total,
            ],
            'emisor' => [
                'id' => $emisor['id'],
                'nombre' => $emisor['nombre'],
                'rfc' => $emisor['rfc'],
                'regimen_fiscal' => $emisor['regimen_fiscal'],
            ],
            'receptor' => $perfil === null ? null : [
                'cliente_id' => $clienteId,
                'perfil_id' => $perfilId,
                'nombre' => $perfil['razon_social'],
                'rfc' => $perfil['rfc'],
                'domicilio_fiscal' => $perfil['cp'],
                'regimen_fiscal' => $perfil['regimen_fiscal'],
                'uso_cfdi' => $usoClave,
            ],
            'partidas' => $partidas,
            'persistido' => false,
            'timbrado' => false,
        ];
    }

    /** @param array<string, mixed> $perfil
     *  @return array<int, string>
     */
    private function validarPerfil(array $perfil): array
    {
        $errores = [];
        if (trim((string) ($perfil['razon_social'] ?? '')) === '') {
            $errores[] = 'Falta la razón social fiscal.';
        }
        if (!$this->rfcValido((string) ($perfil['rfc'] ?? ''))) {
            $errores[] = 'El RFC no tiene un formato válido.';
        }
        if (!$this->cpValido((string) ($perfil['cp'] ?? ''))) {
            $errores[] = 'El código postal debe tener cinco dígitos.';
        }
        if (!preg_match('/^\d{3}$/', (string) ($perfil['regimen_fiscal'] ?? ''))) {
            $errores[] = 'Falta una clave de régimen fiscal válida.';
        }
        return $errores;
    }

    private function rfcValido(string $rfc): bool
    {
        return preg_match('/^[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}$/u', strtoupper(trim($rfc))) === 1;
    }

    private function cpValido(string $cp): bool
    {
        return preg_match('/^\d{5}$/', trim($cp)) === 1;
    }

    /** @return array<string, array<string, mixed>> */
    private function usosCfdi(): array
    {
        $ambos = ['fisica' => true, 'moral' => true];
        $fisica = ['fisica' => true, 'moral' => false];
        $datos = [
            'G01' => ['Adquisición de mercancías', $ambos],
            'G02' => ['Devoluciones, descuentos o bonificaciones', $ambos],
            'G03' => ['Gastos en general', $ambos],
            'I01' => ['Construcciones', $ambos],
            'I02' => ['Mobiliario y equipo de oficina por inversiones', $ambos],
            'I03' => ['Equipo de transporte', $ambos],
            'I04' => ['Equipo de cómputo y accesorios', $ambos],
            'I05' => ['Dados, troqueles, moldes, matrices y herramental', $ambos],
            'I06' => ['Comunicaciones telefónicas', $ambos],
            'I07' => ['Comunicaciones satelitales', $ambos],
            'I08' => ['Otra maquinaria y equipo', $ambos],
            'D01' => ['Honorarios médicos, dentales y gastos hospitalarios', $fisica],
            'D02' => ['Gastos médicos por incapacidad o discapacidad', $fisica],
            'D03' => ['Gastos funerales', $fisica],
            'D04' => ['Donativos', $fisica],
            'D05' => ['Intereses reales por créditos hipotecarios', $fisica],
            'D06' => ['Aportaciones voluntarias al SAR', $fisica],
            'D07' => ['Primas por seguros de gastos médicos', $fisica],
            'D08' => ['Gastos de transportación escolar obligatoria', $fisica],
            'D09' => ['Depósitos para el ahorro y planes de pensiones', $fisica],
            'D10' => ['Pagos por servicios educativos', $fisica],
            'S01' => ['Sin efectos fiscales', $ambos],
            'CP01' => ['Pagos', $ambos],
            'CN01' => ['Nómina', $fisica],
        ];
        $resultado = [];
        foreach ($datos as $clave => [$descripcion, $tipos]) {
            $resultado[$clave] = ['clave' => $clave, 'descripcion' => $descripcion] + $tipos;
        }
        return $resultado;
    }

    /** @param array<int, array<string, mixed>> $catalogo
     *  @return array<string, mixed>|null
     */
    private function buscarCatalogoPorId(array $catalogo, int $id): ?array
    {
        foreach ($catalogo as $fila) {
            if ((int) $fila['id'] === $id) {
                return $fila;
            }
        }
        return null;
    }

    /** @param array<int, int> $ids
     *  @return array<int, array<string, mixed>>
     */
    private function obtenerConceptosPorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $disponibles = $this->listarConceptos();
        $solicitados = array_fill_keys($ids, true);
        $resultado = [];
        foreach ($disponibles as $concepto) {
            if (isset($solicitados[(int) $concepto['id']])) {
                $resultado[(int) $concepto['id']] = $concepto;
            }
        }
        return $resultado;
    }

    private function dinero(float $cantidad): string
    {
        return '$' . number_format($cantidad, 2, '.', ',');
    }
}
