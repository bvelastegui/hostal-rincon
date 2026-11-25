<?php

namespace App\Actions;

use App\Models\CashMovement;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class GroupCashMovementHistory
{
    /** @var array<int, int> */
    private array $originals;

    /** @var array<int, int> */
    private array $replacements;

    /** @var array<int, true> */
    private array $expanded = [];

    /** @param Builder<CashMovement> $scope */
    public function __construct(private readonly Builder $scope)
    {
        $this->originals = (clone $scope)->whereNotNull('replaces_movement_id')
            ->whereIn('replaces_movement_id', (clone $scope)->select('id'))
            ->pluck('replaces_movement_id', 'id')
            ->map(fn ($id): int => (int) $id)->all();
        $this->replacements = array_flip($this->originals);
    }

    /**
     * @param  Builder<CashMovement>  $filtered
     * @return Builder<CashMovement>
     */
    public function roots(Builder $filtered, bool $matchChildren): Builder
    {
        if ($this->replacements === []) {
            return $filtered;
        }

        if ($matchChildren) {
            $matches = (clone $filtered)->whereIntegerInRaw('id', array_keys($this->replacements))->pluck('id');

            foreach ($matches as $id) {
                while (isset($this->replacements[$id])) {
                    $id = $this->replacements[$id];
                    $this->expanded[$id] = true;
                }
            }
        }

        $rootMatches = array_keys(array_diff_key($this->expanded, $this->replacements));
        $roots = $rootMatches === [] ? $filtered : (clone $this->scope)->where(fn (Builder $query) => $query
            ->whereIn('id', (clone $filtered)->select('id'))->orWhereIntegerInRaw('id', $rootMatches));

        return $roots->whereIntegerNotInRaw('id', collect(array_keys($this->replacements)));
    }

    /**
     * @param  Collection<int, CashMovement>  $rows
     * @param  list<string>  $relations
     * @param  Closure(CashMovement): array<string, mixed>  $serialize
     * @return Collection<int, array<string, mixed>>
     */
    public function serialize(Collection $rows, array $relations, Closure $serialize): Collection
    {
        $originalIds = [];

        foreach ($rows as $movement) {
            $id = $movement->id;

            while (isset($this->originals[$id])) {
                $id = $this->originals[$id];
                $originalIds[] = $id;
            }
        }

        $originals = $originalIds === [] ? collect() : (clone $this->scope)
            ->whereIntegerInRaw('id', $originalIds)->with($relations)->get()->keyBy('id');

        return $rows->map(fn (CashMovement $movement): array => $this->row($movement, $originals, $serialize));
    }

    /**
     * @param  Collection<int, CashMovement>  $originals
     * @param  Closure(CashMovement): array<string, mixed>  $serialize
     * @return array<string, mixed>
     */
    private function row(CashMovement $movement, Collection $originals, Closure $serialize): array
    {
        $row = $serialize($movement);
        $original = $originals->get($this->originals[$movement->id] ?? 0);

        if ($original !== null) {
            $row['children'] = [$this->row($original, $originals, $serialize)];
            $row['expandHistory'] = isset($this->expanded[$movement->id]);
        }

        return $row;
    }
}
