import { useEffect, useRef, useState } from 'react'

export type AdoptedSignature =
  | { kind: 'typed'; name: string; font: string }
  | { kind: 'drawn' | 'uploaded'; image: string }

type Props = {
  signerName: string
  fonts: Record<string, string>
  onAdopt: (signature: AdoptedSignature) => Promise<void> | void
  busy?: boolean
}

type Mode = 'draw' | 'type' | 'upload'

const MAX_UPLOAD_BYTES = 2 * 1024 * 1024

/**
 * The three ways to adopt a signature, matching what Acrobat offers: draw it,
 * type it in a script font, or upload an image of a real one.
 *
 * Whatever happens here is a *preview*. The artefact that ends up in the sealed
 * PDF is produced on the server — typed names are re-rendered there, and
 * uploads are decoded and re-encoded there — so the bytes that get signed are
 * bytes the server produced and hashed, not bytes a browser sent it.
 */
export default function SignaturePad({ signerName, fonts, onAdopt, busy }: Props) {
  const [mode, setMode] = useState<Mode>('draw')
  const [typedName, setTypedName] = useState(signerName)
  const [font, setFont] = useState(Object.keys(fonts)[0] ?? 'great-vibes')
  const [uploaded, setUploaded] = useState<string | null>(null)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [hasInk, setHasInk] = useState(false)

  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const lastPoint = useRef<{ x: number; y: number } | null>(null)

  /* ------------------------------------------------------------- drawing */

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas || mode !== 'draw') return

    // Back the canvas at device resolution so strokes are not soft on HiDPI
    // screens, then scale the context so drawing still uses CSS pixels.
    const ratio = window.devicePixelRatio || 1
    const rect = canvas.getBoundingClientRect()
    canvas.width = rect.width * ratio
    canvas.height = rect.height * ratio

    const context = canvas.getContext('2d')
    if (!context) return
    context.scale(ratio, ratio)
    context.lineWidth = 2.2
    context.lineCap = 'round'
    context.lineJoin = 'round'
    context.strokeStyle = '#0f172a'
  }, [mode])

  function pointFrom(event: React.PointerEvent<HTMLCanvasElement>) {
    const rect = event.currentTarget.getBoundingClientRect()
    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
  }

  function startStroke(event: React.PointerEvent<HTMLCanvasElement>) {
    event.currentTarget.setPointerCapture(event.pointerId)
    drawing.current = true
    lastPoint.current = pointFrom(event)
  }

  function extendStroke(event: React.PointerEvent<HTMLCanvasElement>) {
    if (!drawing.current) return
    const context = canvasRef.current?.getContext('2d')
    const from = lastPoint.current
    if (!context || !from) return

    const to = pointFrom(event)
    context.beginPath()
    context.moveTo(from.x, from.y)
    context.lineTo(to.x, to.y)
    context.stroke()

    lastPoint.current = to
    if (!hasInk) setHasInk(true)
  }

  function endStroke() {
    drawing.current = false
    lastPoint.current = null
  }

  function clearCanvas() {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')
    if (!canvas || !context) return
    context.clearRect(0, 0, canvas.width, canvas.height)
    setHasInk(false)
  }

  /**
   * Crop the transparent margin off the drawn stroke.
   *
   * Without this the exported PNG is the whole pad, so a small signature ends
   * up shrunk to fit its field with most of the box empty. Trimming means the
   * ink itself fills the field.
   */
  function trimmedDataUrl(): string | null {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')
    if (!canvas || !context) return null

    const { width, height } = canvas
    const { data } = context.getImageData(0, 0, width, height)

    let top = height
    let left = width
    let right = 0
    let bottom = 0

    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        if (data[(y * width + x) * 4 + 3] === 0) continue
        if (y < top) top = y
        if (y > bottom) bottom = y
        if (x < left) left = x
        if (x > right) right = x
      }
    }

    if (right < left || bottom < top) return null

    const pad = 8
    left = Math.max(0, left - pad)
    top = Math.max(0, top - pad)
    right = Math.min(width - 1, right + pad)
    bottom = Math.min(height - 1, bottom + pad)

    const out = document.createElement('canvas')
    out.width = right - left + 1
    out.height = bottom - top + 1
    out
      .getContext('2d')
      ?.drawImage(canvas, left, top, out.width, out.height, 0, 0, out.width, out.height)

    return out.toDataURL('image/png')
  }

  /* -------------------------------------------------------------- upload */

  function handleUpload(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    setUploadError(null)
    if (!file) return

    // A client-side check for the user's benefit only. The server re-checks the
    // magic bytes and fully re-encodes the image — this just avoids a pointless
    // round trip with a 20 MB holiday photo.
    if (!['image/png', 'image/jpeg'].includes(file.type)) {
      setUploadError('Upload a PNG or JPEG image.')
      return
    }
    if (file.size > MAX_UPLOAD_BYTES) {
      setUploadError('That image is larger than 2 MB.')
      return
    }

    const reader = new FileReader()
    reader.onload = () => setUploaded(String(reader.result))
    reader.onerror = () => setUploadError('That file could not be read.')
    reader.readAsDataURL(file)
  }

  /* --------------------------------------------------------------- adopt */

  const canAdopt =
    (mode === 'draw' && hasInk) ||
    (mode === 'type' && typedName.trim().length > 0) ||
    (mode === 'upload' && uploaded !== null)

  async function adopt() {
    if (mode === 'type') {
      await onAdopt({ kind: 'typed', name: typedName.trim(), font })
      return
    }
    if (mode === 'upload' && uploaded) {
      await onAdopt({ kind: 'uploaded', image: uploaded })
      return
    }
    const image = trimmedDataUrl()
    if (image) await onAdopt({ kind: 'drawn', image })
  }

  const tabs: { key: Mode; label: string }[] = [
    { key: 'draw', label: 'Draw' },
    { key: 'type', label: 'Type' },
    { key: 'upload', label: 'Upload' },
  ]

  return (
    <div className="rounded-lg border border-slate-200 bg-white">
      <div className="flex border-b border-slate-200">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            type="button"
            onClick={() => setMode(tab.key)}
            className={`flex-1 px-4 py-2.5 text-sm font-medium transition ${
              mode === tab.key
                ? 'border-b-2 border-blue-700 text-blue-700'
                : 'text-slate-500 hover:text-slate-700'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div className="p-4">
        {mode === 'draw' && (
          <div>
            <canvas
              ref={canvasRef}
              className="signature-canvas h-40 w-full rounded-md border border-dashed border-slate-300 bg-slate-50"
              onPointerDown={startStroke}
              onPointerMove={extendStroke}
              onPointerUp={endStroke}
              onPointerLeave={endStroke}
            />
            <div className="mt-2 flex items-center justify-between">
              <p className="text-xs text-slate-500">Sign with a mouse, trackpad or finger.</p>
              <button
                type="button"
                onClick={clearCanvas}
                className="text-xs font-medium text-slate-600 underline hover:text-slate-900"
              >
                Clear
              </button>
            </div>
          </div>
        )}

        {mode === 'type' && (
          <div className="space-y-3">
            <input
              value={typedName}
              onChange={(e) => setTypedName(e.target.value)}
              maxLength={120}
              placeholder="Type your full name"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
            />

            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {Object.entries(fonts).map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setFont(key)}
                  className={`rounded-md border px-3 py-2 text-left transition ${
                    font === key
                      ? 'border-blue-600 bg-blue-50'
                      : 'border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <span
                    className="block truncate text-2xl leading-tight text-slate-900"
                    style={{ fontFamily: `'${label}', cursive` }}
                  >
                    {typedName.trim() || 'Your name'}
                  </span>
                  <span className="text-[11px] text-slate-500">{label}</span>
                </button>
              ))}
            </div>
          </div>
        )}

        {mode === 'upload' && (
          <div className="space-y-3">
            <input
              type="file"
              accept="image/png,image/jpeg"
              onChange={handleUpload}
              className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200"
            />
            {uploadError && <p className="text-xs text-red-600">{uploadError}</p>}
            {uploaded && (
              <div className="rounded-md border border-dashed border-slate-300 bg-slate-50 p-3">
                <img src={uploaded} alt="Uploaded signature" className="mx-auto max-h-28" />
              </div>
            )}
            <p className="text-xs text-slate-500">
              PNG or JPEG, up to 2 MB. A white background is removed automatically.
            </p>
          </div>
        )}
      </div>

      <div className="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
        <button
          type="button"
          disabled={!canAdopt || busy}
          onClick={adopt}
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300"
        >
          {busy ? 'Saving…' : 'Adopt signature'}
        </button>
      </div>
    </div>
  )
}
