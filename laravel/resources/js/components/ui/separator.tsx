import * as React from "react"
import { Separator as SeparatorPrimitive } from "@base-ui/react/separator"

import { cn } from "cn"

function Separator({
  className,
  orientation = "horizontal",
  decorative = true,
  ...props
}: React.ComponentProps<typeof SeparatorPrimitive> & { decorative?: boolean }) {
  // shadcn's Radix wrapper defaulted decorative=true, hiding the separator from
  // the a11y tree (role="none", no aria-orientation). Base UI's Separator always
  // emits role="separator", so re-apply those overrides ourselves. Base UI merges
  // element props over its defaults (rightmost wins), so role/aria-orientation
  // here override its built-in role="separator"/aria-orientation. When decorative
  // is false, keep Base UI's separator semantics untouched.
  const decorativeProps = decorative
    ? { role: "none", "aria-orientation": undefined }
    : {}
  return (
    <SeparatorPrimitive
      data-slot="separator"
      orientation={orientation}
      className={cn(
        "bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px",
        className
      )}
      {...decorativeProps}
      {...props}
    />
  )
}

export { Separator }
