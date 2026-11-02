<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;

/** Todo lo que el motor necesita. Se guarda completa al congelar una cotización. */
final class Entrada
{
    /**
     * @param  Pieza[]  $piezas
     * @param  array<int, Material>  $materiales  indexados por id
     * @param  array<int, Ajuste>  $ajustes  indexados por material_id
     */
    public function __construct(
        public int $cantidad,
        public BigDecimal $frenteM,
        public ?int $pinturaMaterialId,
        public array $piezas,
        public array $materiales,
        public array $ajustes,
        public Parametros $parametros,
    ) {}

    public function aArreglo(): array
    {
        return [
            'cantidad' => $this->cantidad,
            'frente_m' => (string) $this->frenteM,
            'pintura_material_id' => $this->pinturaMaterialId,
            'piezas' => array_map(fn (Pieza $p) => $p->aArreglo(), $this->piezas),
            'materiales' => array_values(array_map(fn (Material $m) => $m->aArreglo(), $this->materiales)),
            'ajustes' => array_values(array_map(fn (Ajuste $a) => $a->aArreglo(), $this->ajustes)),
            'parametros' => $this->parametros->aArreglo(),
        ];
    }

    public static function deArreglo(array $a): self
    {
        $materiales = [];
        foreach ($a['materiales'] as $m) {
            $materiales[(int) $m['id']] = Material::deArreglo($m);
        }
        $ajustes = [];
        foreach ($a['ajustes'] as $x) {
            $ajustes[(int) $x['material_id']] = Ajuste::deArreglo($x);
        }
        return new self(
            (int) $a['cantidad'], Dec::de($a['frente_m'] ?? 0),
            isset($a['pintura_material_id']) ? (int) $a['pintura_material_id'] : null,
            array_map(fn ($p) => Pieza::deArreglo($p), $a['piezas']),
            $materiales, $ajustes, Parametros::deArreglo($a['parametros']),
        );
    }
}
