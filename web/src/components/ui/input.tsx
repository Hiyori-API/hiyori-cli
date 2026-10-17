import { TextInput } from "@carbon/react"
import { useId } from "react"
import { cn } from "cn"

/**
 * Carbon `TextInput` behind the dashboard's bare-`<input>` API.
 *
 * Carbon's TextInput is a labelled control (wrapper + label + input + helper
 * text) and its types require both `id` and `labelText`, while the dashboard's
 * `Input` is the bare field that page code labels itself. So the surface is the
 * standard input props, and Carbon's two required props are satisfied here: a
 * generated id when the caller gives none, and an empty `labelText` plus
 * `hideLabel` so no label slot is rendered.
 *
 * Height is pinned to 32px for the same density reason as Button — Carbon's
 * default text input is 40px.
 */
export interface InputProps
  extends Omit<React.ComponentProps<"input">, "size"> {
  invalid?: boolean
  invalidText?: string
  helperText?: string
}

function Input({
  className,
  type = "text",
  id,
  invalid,
  invalidText,
  helperText,
  ...props
}: InputProps) {
  const generated = useId()
  const inputId = id ?? generated

  // Carbon's TextInput types its handlers against `HTMLElement`, but the node
  // Carbon renders is a real `<input>`, so a caller written against the DOM
  // gets an `HTMLInputElement` at runtime. That mismatch is confined to this
  // one cast rather than pushed onto every call site.
  const passthrough = {
    ...props,
    defaultValue:
      typeof props.defaultValue === "string" || typeof props.defaultValue === "number"
        ? props.defaultValue
        : undefined,
  } as unknown as Record<string, unknown>

  return (
    <TextInput
      data-slot="input"
      id={inputId}
      type={type}
      labelText=""
      hideLabel
      invalid={invalid}
      invalidText={invalid && invalidText ? invalidText : undefined}
      helperText={!invalid && helperText ? helperText : undefined}
      // Height/padding/label-gutter overrides live in index.css under
      // `.carbon-input` — Tailwind v4 mis-escapes `__` inside arbitrary
      // variants, so they cannot be expressed as utilities here.
      className={cn("carbon-input", className)}
      {...passthrough}
    />
  )
}

export { Input }
