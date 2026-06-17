<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductCardResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'main_image'     => $this->main_image,
            'price'          => $this->price,
            'discount_value' => $this->discount_value,
            'discount_type'  => $this->discount_type,
            'stock'          => $this->stock,
            'status'         => $this->status,
            'attributes'     => $this->groupedAttributes(),
        ];
    }

    private function groupedAttributes(): array
    {
        $grouped = [];

        foreach ($this->variants as $variant) {
            foreach ($variant->values as $value) {
                $attrName = $value->attribute->name;

                if (!isset($grouped[$attrName])) {
                    $grouped[$attrName] = [
                        'name'   => $attrName,
                        'values' => [],
                    ];
                }

                $alreadyAdded = collect($grouped[$attrName]['values'])
                    ->contains('id', $value->id);

                if (!$alreadyAdded) {
                    $grouped[$attrName]['values'][] = [
                        'id'          => $value->id,
                        'value'       => $value->value,
                        'extra_value' => $value->extra_value,
                    ];
                }
            }
        }

        return array_values($grouped);
    }
}