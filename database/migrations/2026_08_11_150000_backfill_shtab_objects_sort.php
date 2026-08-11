<?php

use App\Models\ShtabObject;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Порядок территорий на Карте становится ручным (drag & drop), поэтому
     * раскладываем текущий вычисляемый порядок в поле sort — корни (продукты и
     * самостоятельные энейблеры) по типу и фокусу, под каждым его дети и внуки.
     * После релиза карта выглядит так же, дальше порядок задаёт Глеб.
     */
    public function up(): void
    {
        $objects = ShtabObject::query()
            ->orderByDesc('focus_level')->orderBy('sort')->orderBy('name')
            ->get();

        $childrenOf = fn (int $parentId) => $objects->where('parent_id', $parentId);
        $roots = $objects
            ->filter(fn (ShtabObject $o): bool => $o->parent_id === null)
            ->sortBy([
                fn (ShtabObject $a, ShtabObject $b): int => strcmp($a->type, $b->type),
                fn (ShtabObject $a, ShtabObject $b): int => $b->focus_level <=> $a->focus_level,
                fn (ShtabObject $a, ShtabObject $b): int => strcmp($a->name, $b->name),
            ]);

        $ordered = collect();

        foreach ($roots as $root) {
            $ordered->push($root);

            foreach ($childrenOf($root->id) as $child) {
                $ordered->push($child);
                $ordered = $ordered->concat($childrenOf($child->id));
            }
        }

        $ordered = $ordered
            ->concat($objects->whereNotIn('id', $ordered->pluck('id')))
            ->unique('id')
            ->values();

        foreach ($ordered as $index => $object) {
            ShtabObject::query()->whereKey($object->id)->update(['sort' => $index + 1]);
        }
    }

    public function down(): void
    {
        // Значения sort остаются как есть — откат не нужен.
    }
};
