<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;

/** Un material del catálogo con su precio vigente ya resuelto. */
final class Material
{
    public function __construct(
        public int $id,
        public string $clave,
        public string $descripcion,
        public string $categoria,
        public string $tipoCalculo,      // LINEAL, AREA, AREA_KG, PESO, PIEZA
        public string $unidad,           // m, m2, kg, pza
        public string $presentacion,
        public BigDecimal $contenido,    // en lámina = peso de la hoja según su calibre
        public BigDecimal $kgM2,         // solo lámina
        public string $modoCarga,        // CONSUMIDO o COMPLETA
        public bool $tienePrecio,
        public BigDecimal $precioPublicado,
        public string $precioPor,        // PRESENTACION o UNIDAD
        public bool $incluyeIva,
        public BigDecimal $tasaIva,
        public string $confianza,
        public int $diasAntiguedad,
    ) {}

    /** Quita el IVA si el precio publicado lo incluía. */
    public function precioSinIva(): BigDecimal
    {
        return $this->incluyeIva
            ? Dec::div($this->precioPublicado, BigDecimal::one()->plus($this->tasaIva))
            : $this->precioPublicado;
    }

    /** $ por unidad de consumo (m, m², kg, pza), sin IVA. */
    public function precioUnitario(): BigDecimal
    {
        if (! $this->tienePrecio) {
            return BigDecimal::zero();
        }
        if ($this->precioPor === 'UNIDAD' || $this->contenido->isZero()) {
            return $this->precioSinIva();
        }
        return Dec::div($this->precioSinIva(), $this->contenido);
    }

    /** $ de la presentación completa (tramo, hoja, rollo…), sin IVA. */
    public function precioPresentacion(): BigDecimal
    {
        if (! $this->tienePrecio) {
            return BigDecimal::zero();
        }
        return $this->precioPor === 'UNIDAD'
            ? $this->precioSinIva()->multipliedBy($this->contenido)
            : $this->precioSinIva();
    }

    public function aArreglo(): array
    {
        return [
            'id' => $this->id, 'clave' => $this->clave, 'descripcion' => $this->descripcion,
            'categoria' => $this->categoria, 'tipo_calculo' => $this->tipoCalculo, 'unidad' => $this->unidad,
            'presentacion' => $this->presentacion, 'contenido' => (string) $this->contenido,
            'kg_m2' => (string) $this->kgM2, 'modo_carga' => $this->modoCarga,
            'tiene_precio' => $this->tienePrecio, 'precio_publicado' => (string) $this->precioPublicado,
            'precio_por' => $this->precioPor, 'incluye_iva' => $this->incluyeIva,
            'tasa_iva' => (string) $this->tasaIva, 'confianza' => $this->confianza,
            'dias_antiguedad' => $this->diasAntiguedad,
        ];
    }

    public static function deArreglo(array $a): self
    {
        return new self(
            (int) $a['id'], $a['clave'], $a['descripcion'], $a['categoria'], $a['tipo_calculo'], $a['unidad'],
            $a['presentacion'], Dec::de($a['contenido']), Dec::de($a['kg_m2'] ?? 0), $a['modo_carga'],
            (bool) $a['tiene_precio'], Dec::de($a['precio_publicado'] ?? 0), $a['precio_por'] ?? 'PRESENTACION',
            (bool) ($a['incluye_iva'] ?? false), Dec::de($a['tasa_iva'] ?? '0.16'), $a['confianza'] ?? '',
            (int) ($a['dias_antiguedad'] ?? 0),
        );
    }
}
