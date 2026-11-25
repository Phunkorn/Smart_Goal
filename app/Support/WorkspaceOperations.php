<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Object-level batches. The enclosing revision CAS supplies the precondition. */
final class WorkspaceOperations
{
    public static function apply(array $document, array $operations): array
    {
        if (! array_is_list($operations) || count($operations) > WorkspaceDesign::MAX_ELEMENTS * 2) {
            throw ValidationException::withMessages(['operations' => 'รายการแก้ไขกระดานไม่ถูกต้อง']);
        }
        $elements = collect($document['elements'])->keyBy('id')->all();
        foreach ($operations as $operation) {
            $id = $operation['id'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
                throw ValidationException::withMessages(['operations' => 'รหัสชิ้นงานไม่ถูกต้อง']);
            }
            if (($operation['type'] ?? null) === 'put' && is_array($operation['element'] ?? null) && ($operation['element']['id'] ?? null) === $id) {
                $elements[$id] = $operation['element'];
            } elseif (($operation['type'] ?? null) === 'delete') {
                unset($elements[$id]);
            } else {
                throw ValidationException::withMessages(['operations' => 'คำสั่งแก้ไขไม่ถูกต้อง']);
            }
        }

        // z is the persistent layer key; stable sorting preserves equal-z legacy documents.
        return ['schema' => 1, 'elements' => collect(array_values($elements))->sortBy('z')->values()->all()];
    }

    public static function delta(array $before, array $after): array
    {
        $old = collect($before['elements'])->keyBy('id');
        $new = collect($after['elements'])->keyBy('id');

        return [
            'upserts' => $new->filter(fn ($element, $id) => $old->get($id) !== $element)->values()->all(),
            'deleted' => $old->keys()->diff($new->keys())->values()->all(),
            'order' => $new->keys()->all(),
        ];
    }
}
