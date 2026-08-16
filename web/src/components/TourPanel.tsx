import { useTour } from '../lib/tour'

/**
 * The guided tour rail.
 *
 * A fixed drawer rather than a layout column: it must be able to appear and
 * disappear without reflowing the screen underneath, so what a reviewer tests
 * with the tour open is exactly what they test with it closed.
 */
export default function TourPanel() {
  const { enabled, toggle, step } = useTour()

  if (!enabled) {
    return (
      <button
        onClick={toggle}
        className="fixed bottom-5 right-5 z-40 rounded-full bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-lg transition hover:bg-slate-700"
      >
        Guided tour
      </button>
    )
  }

  return (
    <aside className="fixed right-0 top-0 z-40 flex h-full w-[380px] flex-col border-l border-slate-200 bg-white">
      <header className="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
        <div>
          <p className="text-sm font-semibold">Guided tour</p>
          <p className="text-xs text-slate-500">Why it is built this way</p>
        </div>
        <button
          onClick={toggle}
          className="rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900"
        >
          Hide
        </button>
      </header>

      <div className="flex-1 overflow-y-auto px-5 py-5">
        {step ? (
          <article>
            <span className="inline-block rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-800">
              {step.stage}
            </span>

            <h2 className="mt-3 text-base font-semibold leading-snug">{step.title}</h2>

            <Section label="What is happening">{step.what}</Section>

            <Section label="The decision">{step.decision}</Section>

            <Section label="Instead of">{step.instead}</Section>

            {step.tryThis && (
              <div className="mt-5 rounded-md border border-emerald-200 bg-emerald-50 p-3">
                <p className="text-[11px] font-medium uppercase tracking-wide text-emerald-800">
                  Try this
                </p>
                <p className="mt-1 text-[13px] leading-relaxed text-emerald-900">
                  {step.tryThis}
                </p>
              </div>
            )}

            {step.file && (
              <p className="mt-5 break-all border-t border-slate-100 pt-4 font-mono text-[11px] text-slate-500">
                {step.file}
              </p>
            )}
          </article>
        ) : (
          <p className="text-sm text-slate-500">
            Move through the application and this panel explains each step as you
            reach it.
          </p>
        )}
      </div>

      <footer className="border-t border-slate-200 px-5 py-3">
        <p className="text-[11px] leading-relaxed text-slate-500">
          This panel only observes. It cannot change what the application does, so
          the flow you are testing is the real one.
        </p>
      </footer>
    </aside>
  )
}

function Section({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="mt-4">
      <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">
        {label}
      </p>
      <p className="mt-1 text-[13px] leading-relaxed text-slate-700">{children}</p>
    </div>
  )
}

/** Reserves space for the drawer so fixed positioning never covers content. */
export function TourInset({ children }: { children: React.ReactNode }) {
  const { enabled } = useTour()

  return (
    <div style={enabled ? { paddingRight: 380 } : undefined} className="min-h-full">
      {children}
    </div>
  )
}
