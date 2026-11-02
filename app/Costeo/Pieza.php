<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;

/** Un renglón del despiece del plano (por UN mueble). Cotas en mm. */
final class Pieza
{
    public function __construct(
        public int $id,
        public int $orden,
        public string $subensamble,
        public string $descripcion,
        public int $materialId,
        public BigDecimal $cantidad,
        public BigDecimal $largoMm,
        public BigDecimal $anchoMm,
        public BigDecimal $perimetroPintableMm, // tubo (LINEAL)
        public BigDecimal $carasPintables,      // lámina / tablero (AREA, AREA_KG)
        public string $notas = '',
    ) {}

    public function aArreglo(): array
    {
        return [
            'id' => $this->id, 'orden' => $this->orden, 'subensamble' => $this->subensamble,
            'descripcion' => $this->descripcion, 'material_id' => $this->materialId,
            'cantidad' => (string) $this->cantidad, 'largo_mm' => (string) $this->largoMm,
            'ancho_mm' => (string) $this->anchoMm, 'perimetro_pintable_mm' => (string) $this->perimetroPintableMm,
            'caras_pintables' => (string) $this->carasPintables, 'notas' => $this->notas,
        ];
    }

    public static function deArreglo(array $a): self
    {
        return new self(
            (int) ($a['id'] ?? 0), (int) ($a['orden'] ?? 0), (string) ($a['subensamble'] ?? ''),
            (string) $a['descripcion'], (int) $a['material_id'], Dec::de($a['cantidad']),
            Dec::de($a['largo_mm'] ?? 0), Dec::de($a['ancho_mm'] ?? 0),
            Dec::de($a['perimetro_pintable_mm'] ?? 0), Dec::de($a['caras_pintables'] ?? 0),
            (string) ($a['notas'] ?? ''),
        );
    }
}
