import * as pdfjsLib from 'pdfjs-dist'
import { useEffect, useRef, useState } from 'react'

// Served from public/ as .js rather than imported as .mjs. pdf.js loads its
// worker through a dynamic import(), which browsers MIME-check strictly, and
// many servers have no mapping for .mjs — they answer application/octet-stream
// and the browser refuses to run it. scripts/copy-pdf-worker.mjs puts the file
// here during predev/prebuild, so the bytes always match the installed version.
pdfjsLib.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js'

export type PageBox = { width: number; height: number }

type Props = {
  /** Raw PDF bytes. */
  data: ArrayBuffer | null
  scale?: number
  /** Rendered pixel size of each page, so overlays can be positioned. */
  onPagesRendered?: (boxes: PageBox[]) => void
  /** Rendered on top of page `index`, inside a positioned wrapper. */
  renderOverlay?: (index: number, box: PageBox) => React.ReactNode
  onPageClick?: (index: number, xNorm: number, yNorm: number, box: PageBox) => void
}

/**
 * Renders every page of a PDF to a canvas and exposes each page's rendered
 * size.
 *
 * Field coordinates are kept normalised to 0..1 rather than pixels, because the
 * same field has to survive this viewer's zoom, a different screen's DPI and,
 * ultimately, PDF user space on the server. Pixels would only be correct at the
 * exact scale they were captured at.
 */
export default function PdfViewer({
  data,
  scale = 1.4,
  onPagesRendered,
  renderOverlay,
  onPageClick,
}: Props) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [boxes, setBoxes] = useState<PageBox[]>([])
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!data) return

    let cancelled = false

    // pdf.js takes ownership of the buffer it is given, so hand it a copy —
    // otherwise a re-render on the same ArrayBuffer throws "detached".
    const bytes = data.slice(0)

    setLoading(true)
    setError(null)

    // A dedicated worker per load, rather than pdf.js's shared global one.
    // Without this, tearing down one load's task also tears down the worker any
    // concurrent load is using — which is exactly what StrictMode's
    // mount/unmount/mount produces: the discarded first run kills the worker the
    // second run is still waiting on, and the viewer hangs on "Rendering…"
    // forever. Owning the worker makes cleanup affect only this load.
    const worker = new pdfjsLib.PDFWorker()
    const task = pdfjsLib.getDocument({ data: bytes, worker })

    task.promise
      .then(async (loaded) => {
        const rendered: PageBox[] = []
        const host = containerRef.current
        if (!host) return

        // Each run renders into its own stage element, appended to the live
        // host. Two things force this shape:
        //
        //   - the canvases must be *connected* to the document while rendering;
        //     pdf.js never settles a render task targeting a detached canvas,
        //     so building into a DocumentFragment hangs forever.
        //   - two overlapping runs — which StrictMode guarantees in development
        //     — must not interleave their writes, or every page appears twice.
        //
        // A private stage per run satisfies both: connected throughout, and
        // swapped in as a single operation once this run is known to be current.
        const stage = document.createElement('div')
        host.appendChild(stage)

        for (let n = 1; n <= loaded.numPages; n++) {
          if (cancelled) {
            stage.remove()
            return
          }

          const page = await loaded.getPage(n)
          const viewport = page.getViewport({ scale })

          const wrapper = document.createElement('div')
          wrapper.className =
            'relative mx-auto mb-4 bg-white shadow-sm ring-1 ring-slate-200'
          wrapper.style.width = `${viewport.width}px`
          wrapper.style.height = `${viewport.height}px`
          wrapper.dataset.pageIndex = String(n - 1)

          const canvas = document.createElement('canvas')
          canvas.width = viewport.width
          canvas.height = viewport.height
          canvas.style.display = 'block'
          wrapper.appendChild(canvas)
          stage.appendChild(wrapper)

          const context = canvas.getContext('2d')
          if (context) {
            await page.render({ canvas, canvasContext: context, viewport }).promise
          }

          rendered.push({ width: viewport.width, height: viewport.height })
        }

        if (cancelled) {
          stage.remove()
          return
        }

        // This run won: drop every other run's stage, keep ours.
        for (const child of Array.from(host.children)) {
          if (child !== stage) child.remove()
        }

        setBoxes(rendered)
        onPagesRendered?.(rendered)
      })
      .catch((e: unknown) => {
        if (!cancelled) setError(e instanceof Error ? e.message : 'Could not render the PDF.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      // Safe now that the worker belongs to this load alone. destroy() lives on
      // the loading task — the resolved PDFDocumentProxy only exposes cleanup().
      void task.destroy().catch(() => {}).finally(() => worker.destroy())
    }
    // onPagesRendered is intentionally excluded: callers pass inline closures,
    // and including it would re-render the document on every parent update.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, scale])

  function handleClick(event: React.MouseEvent<HTMLDivElement>) {
    if (!onPageClick) return

    const wrapper = (event.target as HTMLElement).closest<HTMLElement>('[data-page-index]')
    if (!wrapper) return

    const index = Number(wrapper.dataset.pageIndex)
    const rect = wrapper.getBoundingClientRect()

    onPageClick(index, (event.clientX - rect.left) / rect.width, (event.clientY - rect.top) / rect.height, {
      width: rect.width,
      height: rect.height,
    })
  }

  return (
    <div className="relative">
      {loading && (
        <p className="py-8 text-center text-sm text-slate-500">Rendering document…</p>
      )}
      {error && (
        <p className="rounded-md bg-red-50 p-4 text-sm text-red-700">{error}</p>
      )}

      <div ref={containerRef} onClick={handleClick} />

      {/* Overlays are rendered by React into portalless absolute wrappers that
          mirror each page's position, so field boxes stay aligned with the
          canvas the browser actually painted. */}
      {renderOverlay &&
        boxes.map((box, index) => (
          <PageOverlay key={index} container={containerRef} index={index} box={box}>
            {renderOverlay(index, box)}
          </PageOverlay>
        ))}
    </div>
  )
}

function PageOverlay({
  container,
  index,
  box,
  children,
}: {
  container: React.RefObject<HTMLDivElement | null>
  index: number
  box: PageBox
  children: React.ReactNode
}) {
  const [offset, setOffset] = useState<{ top: number; left: number } | null>(null)

  useEffect(() => {
    const host = container.current
    const wrapper = host?.querySelector<HTMLElement>(`[data-page-index="${index}"]`)
    if (!host || !wrapper) return

    setOffset({
      top: wrapper.offsetTop,
      left: wrapper.offsetLeft,
    })
  }, [container, index, box.width, box.height])

  if (!offset) return null

  return (
    <div
      className="pointer-events-none absolute"
      style={{
        top: offset.top,
        left: offset.left,
        width: box.width,
        height: box.height,
      }}
    >
      {children}
    </div>
  )
}
