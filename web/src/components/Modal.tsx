import { useEffect, useRef } from 'react'

type Props = {
  open: boolean
  title: string
  children: React.ReactNode
}

/**
 * A dialog that asks for an answer.
 *
 * Consent prompts are the one place a modal is genuinely the right control: the
 * question has to be seen, and a card in a sidebar is easy to scroll past.
 *
 * There is deliberately no close button, backdrop dismissal or Escape handler.
 * That is not to trap anyone — every one of these dialogs carries a plainly
 * worded refusal that costs the signer nothing and is recorded as a decision.
 * With no other control on the page, a dismissable dialog would leave the
 * question permanently unanswered and the signer with no way back to it, which
 * is worse than asking them to pick one of two answers.
 */
export default function Modal({ open, title, children }: Props) {
  const panelRef = useRef<HTMLDivElement>(null)
  const previouslyFocused = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (!open) return

    previouslyFocused.current = document.activeElement as HTMLElement | null

    // Stops the page behind scrolling under the dialog on mobile.
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    // Move focus in, so a keyboard or screen-reader user lands on the question
    // rather than continuing from wherever they were on the page behind.
    panelRef.current?.focus()

    return () => {
      document.body.style.overflow = previousOverflow
      previouslyFocused.current?.focus()
    }
  }, [open])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-lg bg-white shadow-xl outline-none"
      >
        <div className="border-b border-slate-200 px-5 py-3.5">
          <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
        </div>

        <div className="p-5">{children}</div>
      </div>
    </div>
  )
}
