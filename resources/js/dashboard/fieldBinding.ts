/**
 * v-model for a choice / dropdown custom field.
 *
 * A field with no value yet has no entry in the values map at all, so a plain
 * `v-model="values[id]"` hands the control `undefined`, which matches none of
 * its options: no radio is checked and a dropdown shows nothing. Reading an
 * unset field as '' selects its "(unset)" / "(none)" option instead, which is
 * what an empty value means (the save skips empty values). Spread it onto the
 * control: `v-bind="bindField(values, id)"`.
 */
export function bindField(values: Record<string, string | undefined>, id: string) {
  return {
    modelValue: values[id] ?? '',
    'onUpdate:modelValue': (value: unknown) => {
      values[id] = value == null ? '' : String(value);
    },
  };
}
