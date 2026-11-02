<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;

/** Parámetros de costeo (los del cliente o los de SULA). */
final class Parametros
{
    public function __construct(
        public BigDecimal $manoObraPct,
        public BigDecimal $overheadPct,
        public bool $overheadIncluyeMo,
        public BigDecimal $margenPct,
        public BigDecimal $rendimientoPintura, // m²/kg
        public BigDecimal $tasaIva,
        public int $vigenciaDias,
    ) {}

    public function aArreglo(): array
    {
        return [
            'mano_obra_pct' => (string) $this->manoObraPct, 'overhead_pct' => (string) $this->overheadPct,
            'overhead_incluye_mo' => $this->overheadIncluyeMo, 'margen_pct' => (string) $this->margenPct,
            'rendimiento_pintura_m2kg' => (string) $this->rendimientoPintura,
            'tasa_iva' => (string) $this->tasaIva, 'vigencia_precios_dias' => $this->vigenciaDias,
        ];
    }

    public static function deArreglo(array $a): self
    {
        return new self(
            Dec::de($a['mano_obra_pct']), Dec::de($a['overhead_pct']), (bool) $a['overhead_incluye_mo'],
            Dec::de($a['margen_pct']), Dec::de($a['rendimiento_pintura_m2kg']), Dec::de($a['tasa_iva']),
            (int) $a['vigencia_precios_dias'],
        );
    }
}
