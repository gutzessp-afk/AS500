<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;

/** Decisión de compra de un material dentro de una cotización. */
final class Ajuste
{
    public function __construct(
        public int $materialId,
        public ?BigDecimal $planCorte,  // presentaciones por mueble; manda sobre la merma
        public BigDecimal $mermaPct,
        public ?string $modoCarga,      // null = el del catálogo
        public string $nota = '',
    ) {}

    public function aArreglo(): array
    {
        return [
            'material_id' => $this->materialId,
            'plan_corte' => $this->planCorte === null ? null : (string) $this->planCorte,
            'merma_pct' => (string) $this->mermaPct, 'modo_carga' => $this->modoCarga, 'nota' => $this->nota,
        ];
    }

    public static function deArreglo(array $a): self
    {
        return new self(
            (int) $a['material_id'],
            ($a['plan_corte'] ?? null) === null || $a['plan_corte'] === '' ? null : Dec::de($a['plan_corte']),
            Dec::de($a['merma_pct'] ?? 0), $a['modo_carga'] ?? null, (string) ($a['nota'] ?? ''),
        );
    }
}
