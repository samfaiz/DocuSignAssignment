import { useEffect, useRef, useState } from 'react'

type Consent = 'granted' | 'denied' | 'unsupported' | 'failed'

type Props = {
  onDecision: (payload: { consent: Consent; image?: string }) => Promise<void>
}

/**
 * The photograph question, asked once inside a dialog.
 *
 * A face image is biometric data, so this is deliberately careful: the camera
 * is not touched until the signer presses a button, the stream stops the
 * instant a picture is taken or the dialog goes away, and refusing is as easy
 * as agreeing. The wording never says "verify" — a photograph without a
 * document check and a liveness test shows that someone was present and
 * willing, not who they are, and implying otherwise would be a lie told at the
 * exact moment the signer is deciding.
 */
export default function PhotoConsent({ onDecision }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)

  const [busy, setBusy] = useState(false)
  const [live, setLive] = useState(false)
  // Set from onLoadedMetadata. Attaching a stream is not the same as having a
  // frame to read: until the video reports real dimensions, drawing it to a
  // canvas yields a 0x0 image.
  const [ready, setReady] = useState(false)
  // Shown inside the dialog. The dialog covers the viewport, so an error
  // rendered by the page underneath is invisible — which looks to the signer
  // exactly like the button doing nothing.
  const [error, setError] = useState<string | null>(null)

  /** The camera must never outlive this component, however it unmounts. */
  function stopCamera() {
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    setLive(false)
    setReady(false)
  }

  useEffect(() => stopCamera, [])

  /**
   * Attaches the stream once the <video> is actually on the page.
   *
   * This cannot be done in startCamera: the element only renders when `live`
   * is true, and setLive schedules a render rather than performing one, so the
   * ref is still null on the line after it. Assigning there silently did
   * nothing and left a black rectangle that never produced a frame.
   */
  useEffect(() => {
    const video = videoRef.current
    const stream = streamRef.current

    if (!live || !video || !stream) return

    video.srcObject = stream
    video.play().catch(() => {
      setError('The camera preview could not start. Try again, or continue without a photo.')
    })
  }, [live])

  async function record(payload: { consent: Consent; image?: string }) {
    setBusy(true)
    setError(null)
    try {
      await onDecision(payload)
      stopCamera()
    } catch {
      // Deliberately leaves the camera running so the signer can simply press
      // the button again, rather than being sent back to the start.
      setError('That could not be saved. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  async function startCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
      void record({ consent: 'unsupported' })
      return
    }

    setBusy(true)
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 720 } },
        audio: false,
      })
      streamRef.current = stream
      // The effect above attaches it once React has rendered the element.
      setLive(true)
    } catch (error) {
      // Refusing the browser prompt is a decision; a camera that is missing or
      // already in use by another application is a different fact.
      const denied =
        error instanceof DOMException &&
        (error.name === 'NotAllowedError' || error.name === 'SecurityError')
      void record({ consent: denied ? 'denied' : 'failed' })
    } finally {
      setBusy(false)
    }
  }

  function capture() {
    const video = videoRef.current

    // A stream can be attached before the browser has decoded a frame, and in
    // that state videoWidth is 0. Drawing it produces a 0x0 canvas and a
    // "data:," URL the server rejects — so refuse here, where the message can
    // actually say something useful.
    if (!video || !video.videoWidth || !video.videoHeight) {
      setError('The camera is still starting. Give it a moment and try again.')
      return
    }

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')
    if (!context) {
      setError('This browser could not capture from the camera.')
      return
    }

    context.drawImage(video, 0, 0)

    // The server re-encodes this anyway, stripping metadata; JPEG here just
    // keeps the request small.
    void record({ consent: 'granted', image: canvas.toDataURL('image/jpeg', 0.85) })
  }

  return (
    <div>
      <p className="text-[13px] leading-relaxed text-slate-700">
        The sender has asked for a photo to accompany this signature. It is taken
        by your own device, stored with the signing record, and appears on the
        certificate attached to the signed document.
      </p>
      <p className="mt-2 text-[12px] leading-relaxed text-slate-500">
        This is not an identity check — no document is examined and no facial
        comparison is made. Declining changes nothing about your signature or its
        validity.
      </p>

      {live && (
        <div className="mt-3 overflow-hidden rounded-md bg-slate-900">
          <video
            ref={videoRef}
            playsInline
            muted
            // Both events, because which one first reports usable dimensions
            // varies by browser, and the capture button stays disabled until
            // one of them does.
            onLoadedMetadata={(event) => setReady(event.currentTarget.videoWidth > 0)}
            onCanPlay={(event) => setReady(event.currentTarget.videoWidth > 0)}
            className="mx-auto block max-h-64 w-auto"
          />
        </div>
      )}

      {error && (
        <p className="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>
      )}

      <div className="mt-4 flex gap-2">
        {live ? (
          <button
            type="button"
            onClick={capture}
            disabled={busy || !ready}
            className="flex-1 rounded-md bg-blue-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-800 disabled:bg-slate-300"
          >
            {busy ? 'Saving…' : ready ? 'Take photo' : 'Starting camera…'}
          </button>
        ) : (
          <button
            type="button"
            onClick={startCamera}
            disabled={busy}
            className="flex-1 rounded-md bg-blue-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-800 disabled:bg-slate-300"
          >
            {busy ? 'Starting…' : 'Open camera'}
          </button>
        )}
        <button
          type="button"
          onClick={() => void record({ consent: 'denied' })}
          disabled={busy}
          className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
        >
          No thanks
        </button>
      </div>
    </div>
  )
}
