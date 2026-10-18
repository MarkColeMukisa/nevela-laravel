<?php

namespace Nevela\Laravel\Concerns;

use InvalidArgumentException;
use Nevela\Laravel\Nevela;

/**
 * What a record's enum value is called, as the descriptor says it and the dashboard
 * shows it:
 *
 *     $request->optionLabel('timeline');   // "In 1 to 3 months", not "quarter"
 *
 * So an app's emails and PDFs use the same words as its dashboard, from one place, and
 * no copy of the labels has to be kept on the model. A value with no label is made
 * readable the way the dashboard does it ("on_hold" → "On hold").
 *
 * Generated models use it when their resource has an enum field.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait OptionLabels
{
    public function optionLabel(string $field): string
    {
        $definition = Nevela::resource(class_basename($this))->fields[$field] ?? null;
        if ($definition === null || $definition->kind !== 'enum') {
            throw new InvalidArgumentException(class_basename($this)." has no enum field \"{$field}\".");
        }
        $value = $this->getAttribute($definition->column());

        return $definition->optionLabel($value === null ? null : (string) ($value instanceof \BackedEnum ? $value->value : $value));
    }
}
